# Tenancy at the database level

Application-level design: [03-architecture/tenancy.md](../03-architecture/tenancy.md). Decision: [ADR-0006](../adr/0006-multi-tenancy-model.md). This page is the SQL contract.

## `tenant_id` conventions

- Every application-plane table has `tenant_id uuid NOT NULL REFERENCES tenants(id)`; `audit_logs.tenant_id`, `inbound_emails.tenant_id` and `roles.tenant_id` are nullable (platform-level rows, inbound mail that names no workspace, global roles).
- Control-plane tables (`tenants`, `domains`, `platform_users`, `platform_settings`, framework tables) have none.
- Secondary tables (comments, events, timers, deliveries, pivots) carry `tenant_id` too, even though it is derivable, so RLS is uniform and indexes can lead with it.
- `tenant_id` is filled by `BelongsToTenant` on create and is immutable (a `BEFORE UPDATE` trigger raises on change — the one trigger in the schema).

## Cross-tenant foreign keys

A plain FK `tickets.contact_id → contacts.id` would accept a contact from another tenant. For the hot relations we use **composite foreign keys** so the database itself refuses cross-tenant links:

```sql
ALTER TABLE contacts ADD CONSTRAINT contacts_tenant_id_key UNIQUE (tenant_id, id);
ALTER TABLE tickets ADD CONSTRAINT tickets_contact_fk
  FOREIGN KEY (tenant_id, contact_id) REFERENCES contacts (tenant_id, id);
```

| Child | Composite FKs |
|---|---|
| tickets | contact, organization, category, team, assigned_agent (agent_profiles), duplicate_of (tickets) |
| ticket_comments, mediables, ticket_events, ticket_assignments, ticket_sla_timers, ticket_duplicate_suggestions | ticket (and candidate ticket) |
| agent_skills, team_members, category_skill | both sides |
| ticket_sla_timers | policy |
| webhook_deliveries | subscription |
| contacts | organization |

Everything else (e.g. `priority_override_by → users`) relies on application validation, RLS and the isolation tests. The `(tenant_id, id)` unique constraints cost one extra index per referenced table and are worth it: they turn a class of bugs into constraint violations.

## Row-level security

Enabled by M3-07. Every table in `TenantTables::all()` (the `PRIMARY` and `NULLABLE` lists) has row-level security enabled and **forced**, with one policy named `tenant_isolation`. `FORCE` makes the policy apply to the table owner as well, so no connection of the application reads across tenants, not even `pgsql_owner`.

```sql
-- NOT NULL tenant tables
ALTER TABLE {table} ENABLE ROW LEVEL SECURITY;
ALTER TABLE {table} FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON {table}
  USING      (tenant_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid)
  WITH CHECK (tenant_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid);
```

`NULLIF(…, '')` is needed because a custom setting that has been set once on a connection reads as `''`, not NULL, after it is cleared; `''::uuid` would raise. Unset or cleared, the expression is NULL, `tenant_id = NULL` matches nothing, and the table **fails closed**: a `SELECT` returns zero rows, an `UPDATE` or `DELETE` reaches none, and an `INSERT` raises `42501 new row violates row-level security policy`.

Nullable variants (`audit_logs`, `inbound_emails` since M3-19, `roles`) use `IS NOT DISTINCT FROM`, so the central context (no tenant) is the "NULL tenant":

```sql
-- audit_logs: platform entries only centrally, a tenant's entries only inside it
CREATE POLICY tenant_isolation ON audit_logs
  USING      (tenant_id IS NOT DISTINCT FROM NULLIF(current_setting('app.current_tenant', true), '')::uuid)
  WITH CHECK (tenant_id IS NOT DISTINCT FROM NULLIF(current_setting('app.current_tenant', true), '')::uuid);
-- roles: the same, plus the global default roles readable everywhere
CREATE POLICY tenant_isolation ON roles USING (…same…) WITH CHECK (…same…);
CREATE POLICY global_roles_read ON roles FOR SELECT USING (tenant_id IS NULL);
```

A workspace therefore sees the global roles but can neither update nor delete them (the `UPDATE`/`DELETE` path only has `tenant_isolation`), and only the central context writes them: `SyncPermissionCatalogue` steps out of a tenant with `tenancy()->central()` for the sync.

Not under row-level security: the control plane (`tenants`, `domains`, `platform_users`, …), the credential tables read before a tenant is known (`TenantTables::CREDENTIALS`: `sessions`, `personal_access_tokens`, `oauth_clients`, `oauth_access_tokens`), and `tenant_counters` and `password_reset_tokens`, which are keyed by tenant and only reached with an explicit tenant id.

### Generated from the registry

The migration `Tenancy/…/2026_09_21_180000_enable_row_level_security.php` loops over `TenantTables::all()` and calls `TenantTables::enableRowLevelSecurity($table)` for every table that exists; `down()` calls `disableRowLevelSecurity()`. Both helpers are idempotent (`DROP POLICY IF EXISTS` first).

**A new tenant table** (after M3-07): list it in `TenantTables` in the same change, and in its create migration call, after the table exists,

```php
TenantTables::protectTenantId('things');
TenantTables::enableRowLevelSecurity('things');   // down(): Schema::dropIfExists() removes the policy with the table
```

The M3-07 migration only covered the tables that existed when it ran, so the call is required. `tests/Isolation/RowLevelSecurityTest.php` fails for any `TenantTables::all()` row without `relrowsecurity`, `relforcerowsecurity` and a `tenant_isolation` policy.

**Data migrations on tenant tables** that run after M3-07 see no rows on the owner connection either (the policies are forced). They loop over `tenants` and set the tenant per iteration (`SELECT set_config('app.current_tenant', ?, true)` inside a transaction, or `Tenant::run()`), never `ALTER TABLE … NO FORCE`.

## Roles and grants

```sql
CREATE ROLE helpdesk_owner LOGIN NOSUPERUSER NOCREATEROLE NOBYPASSRLS;
CREATE ROLE helpdesk_app   LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;
CREATE ROLE helpdesk_backup LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE BYPASSRLS;   -- pg_dump only
GRANT CONNECT, TEMPORARY ON DATABASE helpdesk TO helpdesk_app, helpdesk_backup;
GRANT USAGE ON SCHEMA public TO helpdesk_app, helpdesk_backup;
ALTER DEFAULT PRIVILEGES FOR ROLE helpdesk_owner IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO helpdesk_app;
ALTER DEFAULT PRIVILEGES FOR ROLE helpdesk_owner IN SCHEMA public
  GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO helpdesk_app;
ALTER DEFAULT PRIVILEGES FOR ROLE helpdesk_owner IN SCHEMA public
  GRANT EXECUTE ON FUNCTIONS TO helpdesk_app;
-- append-only tables revoke UPDATE, DELETE (and TRUNCATE) from the app role in their own migrations
```

The roles and default privileges come from `infra/postgres/init/10-roles-and-databases.sh`, which the Compose database runs on an empty volume and CI runs against its service container. The M3-07 migration adds no grants: the default privileges already give the app role DML on every table the owner creates, and never `TRUNCATE` (which bypasses row-level security; the isolation suite asserts the app role lacks it on every tenant table). Migrations run as `helpdesk_owner`; the application, Horizon and the scheduler connect as `helpdesk_app`, which owns nothing and has neither `SUPERUSER` nor `BYPASSRLS`. The owner role has no `BYPASSRLS` either, so a `SECURITY DEFINER` function owned by it would still be filtered; nothing uses one.

### Where the owner connection is used

| Place | Why it cannot be the app role |
|---|---|
| `php artisan migrate` (`just migrate`, deploy, `Tests\TestCase::migrateFreshUsing()`) | DDL and object ownership |
| E6 history experiment (`HistoryExperiment::triggerOverhead`, test database only) | `ALTER TABLE tickets DISABLE TRIGGER` inside a rolled-back transaction; it sets the experiment workspace with `set_config(…, true)` first, because the policies apply to the owner too |

Nothing else. Work that spans tenants initialises each tenant instead of bypassing the policies:

| Cross-tenant work | How |
|---|---|
| Platform provisioning (`ProvisionTenant`) | creates the `tenants`, `tenant_counters` and `domains` rows centrally, then seeds categories, the SLA policy, media folders and the owner inside `$tenant->run()` on the app role. The earlier plan put it on the owner connection; that would gain nothing, since the owner is filtered as well. |
| Platform audit entries (`tenant.created`, `tenant.suspended`, …) | written centrally with a NULL tenant, which the `audit_logs` policy allows only in the central context |
| `sla:evaluate`, `webhooks:retry-due` | visit every active tenant (`Tenant::active()->cursor()`) and query the due rows inside `$tenant->run()`; a tenant with nothing due costs one indexed query. They used to read the due tenant ids across all tenants first. |
| `webhooks:prune` | deletes expired deliveries inside each tenant, suspended ones included |
| `mail:fetch-inbound` (M3-19) | routes each message centrally, asking each active tenant inside `$tenant->run()` whether it holds the ticket id of a `ticket+<uuid>@` address (one indexed query each), then writes the log row, comment or ticket inside that tenant; a message that names no tenant is a platform row with a NULL tenant, which only the central context sees |
| `tickets:auto-close`, `tickets:reevaluate-priority`, `agents:reconcile-workload`, `media:cleanup`, `reports:snapshot-daily`, `reports:rebuild`, `reports:verify` | already looped `Tenant::active()` with `$tenant->run()` |
| Global roles and permissions (`identity:sync-permissions`, `DatabaseSeeder`) | central context (`tenancy()->central()`) |
| Demo dataset (`demo:reset`, `demo:tick`, M3-13) | deletes the `acme` and `globex` tenant rows by id on the app role (the foreign keys cascade; referential actions are not filtered by the policies, and the change-capture trigger skips rows of a deleted tenant), then replays the dataset inside each new workspace; `demo:tick` moves timers inside each demo workspace. No other workspace is read |

## Setting lifecycle

**Choice: session-level settings (`set_config(…, false)`) written when a tenant is initialised and cleared when it ends, not `SET LOCAL`.** `SET LOCAL` lasts one transaction, and neither a request nor a job runs in one transaction (reads happen outside transactions, and a job commits several). Session settings are safe because there is no connection pooler (PgBouncer in transaction mode would hand the session to someone else) and because every place a connection outlives its context is covered:

| Moment | What happens | Where |
|---|---|---|
| `tenancy()->initialize($tenant)` | one statement: `SELECT set_config('app.current_tenant', ?, false), set_config('app.request_id', …), set_config('app.actor_type', …), set_config('app.actor_id', …)` | `RlsTenancyBootstrapper::bootstrap` |
| `tenancy()->end()` (request `terminate`, after a job, end of `Tenant::run`) | the same four settings set to `''` | `RlsTenancyBootstrapper::revert` |
| Reconnect (`DB::reconnect()`, lost connection) | a new session starts empty; `ConnectionEstablished` re-applies the current tenant | `RlsTenancyBootstrapper::reapply` |
| Rollback or rollback to a savepoint | PostgreSQL also rolls back a `set_config` made inside it, so `TransactionRolledBack` re-applies the current state | `RlsTenancyBootstrapper::reapplyAfterRollback` |
| Statement inside an already failed transaction (`25P02`) | the write is skipped instead of hiding the original error; the rollback that must follow re-applies it | `RlsTenancyBootstrapper::apply` |
| `$tenant->run($callback)` throws | our `Tenant::run()` restores the previous tenant (or ends tenancy) in `finally`; stancl's only did so on success | `App\Modules\Tenancy\Models\Tenant::run` |
| Queue worker, tenant job | stancl's `QueueTenancyBootstrapper` initialises the tenant from the payload before `handle()` and ends it after | stancl |
| Queue worker, central job | stancl ends tenancy, and a `JobProcessing` listener clears the four settings on the connection whatever the process believes, so a Horizon connection can never carry the previous job's tenant into a central job | `TenancyServiceProvider` |
| Console commands and the scheduler | every command starts central in its own process; tenant loops use `$tenant->run()` | commands |
| PHP-FPM | the tenant is initialised per request and ended in `terminate`; the PDO connection is not persistent | `ResolveTenantFromPrincipal`, `InitializeTenancyFromWorkspace` |

The actor settings feed the change-capture trigger. `record_entity_change()` runs as the invoker (the app role, no `SECURITY DEFINER`): it inserts into `entity_changes` with the changed row's `tenant_id`, which the policy accepts because the row itself had to pass the same policy, and it reads the previous version from `entity_changes` inside the same tenant. Report writers run inside the tenant through the same bootstrapper.

## Notifications table

Laravel's `notifications` table is polymorphic on `notifiable` and has no tenant column; a user who belongs to two tenants (V1) would see a mixed feed and the table could not be RLS'd. The migration adds `tenant_id uuid NOT NULL`, the `Notification` model uses `BelongsToTenant`, and the database notification channel is wrapped so `tenant_id` is filled from the current tenant.

## Per-tenant counters

```sql
UPDATE tenant_counters SET next_ticket_number = next_ticket_number + 1
WHERE tenant_id = $1 RETURNING next_ticket_number - 1 AS number;
```

Executed inside the ticket-creation transaction; the row lock serialises concurrent creations per tenant, giving gapless numbers (a rolled-back transaction rolls the counter back too). Sequences are not used because they are neither per-tenant nor gapless.

## Testing RLS

The whole backend suite runs as `helpdesk_app`; `Tests\TestCase` migrates through `pgsql_owner` (the same in CI).

| Test | Where |
|---|---|
| ENABLE and FORCE on every `TenantTables::all()` table; a `tenant_isolation` policy (`ALL`, permissive, on `app.current_tenant`) per table; no other policy but `global_roles_read`; credential and control-plane tables without RLS; no `TRUNCATE` for the app role | `tests/Isolation/RowLevelSecurityTest.php` §catalog |
| App role is safe: `rolsuper` and `rolbypassrls` false, owns no tenant table | `tests/Isolation/SchemaTest.php` |
| Fail-closed: outside a tenant, `DB::table('tickets')->count()` = 0 and `Ticket::withoutTenancy()` = 0 | `RowLevelSecurityTest` §raw-query backstop |
| Raw query backstop: inside A, a raw count equals A's rows; `where tenant_id = B` = 0 | same |
| Writes: an insert with B's `tenant_id` in A, or any tenant row centrally, raises `42501`; an update or delete of B's rows in A reaches 0 rows | same |
| Every seedable tenant model: B's row is invisible to a raw query centrally and in A, visible in B | same (dataset over `TenantModelInventory::seedablePrimary()`) |
| Nullable policies: platform audit entries and unrouted inbound email only centrally, a workspace's inbound email only inside it; global roles visible in every tenant but not writable by one | `RowLevelSecurityTest` §nullable tenant tables |
| Lifecycle: cleared on end; restored after a failing `Tenant::run()` inside a transaction; re-applied after a rollback; no leak from a failing tenant job into the next central job on one worker connection; a stale setting cleared before a central job | `RowLevelSecurityTest` §setting lifecycle |
| Reflection | every Eloquent model is classified and its table is in `TenantTables` or the documented allow-list (`ModelReflectionTest`) |

## Dedicated-database migration path

1. Set `tenants.placement = 'dedicated'`, create the database and roles (job).
2. Enable `DatabaseTenancyBootstrapper` for dedicated tenants only (custom bootstrapper checks placement) and add `HasDatabase` to `Tenant`.
3. Run `tenants:migrate --tenants=<id>`; copy rows (`pg_dump --table` with `WHERE tenant_id`) into the new database; verify counts; flip placement; delete rows from the shared database.
4. `tenant_id` columns, composite FKs and RLS remain in the dedicated database unchanged (harmless); the setting is still applied.

No MVP work beyond the `placement` column and the bootstrapper seam.
