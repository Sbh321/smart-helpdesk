# Migrations and seeding

## Location and loading

Each module owns `app/Modules/<Module>/Database/Migrations`; the module service provider calls `loadMigrationsFrom()`. Framework/package migrations (Sanctum, Passport, spatie, Horizon, Telescope, health, notifications, sessions, cache, jobs) stay in `database/migrations` and are published once, then edited (adding `tenant_id`, UUID keys) — package migrations are never re-published.

## Naming and ordering

`YYYY_MM_DD_HHMMSS_<module>_<verb>_<subject>.php`, e.g. `2026_09_22_100000_tickets_create_tickets_table.php`. Laravel orders by filename across all paths, so cross-module dependencies are expressed by timestamp bands:

| Band (HHMMSS) | Content |
|---|---|
| 000000–009999 | extensions, control plane (tenants, domains, platform_users, platform_settings) |
| 010000–019999 | identity (users, invitations, spatie, sanctum, passport) |
| 020000–029999 | contacts, agents, categories, skills, teams |
| 030000–039999 | tickets and children |
| 040000–049999 | SLA, automation tables |
| 050000–059999 | notifications, integrations, analytics, audit |
| 090000–099999 | composite FKs that need both tables, RLS policies, grants |

Composite foreign keys and RLS live in the last band so every table exists first. A test asserts that no migration filename violates its module's band.

## Statements that need raw SQL

```php
DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
DB::statement("ALTER TABLE tickets ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (
    setweight(to_tsvector('english', coalesce(title, '')), 'A') ||
    setweight(to_tsvector('english', coalesce(description, '')), 'B')) STORED");
DB::statement('CREATE INDEX tickets_search_gin ON tickets USING GIN (search_vector)');
DB::statement('CREATE INDEX tickets_title_trgm_gin ON tickets USING GIN (title gin_trgm_ops)');
DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_status_check CHECK (status IN ('open','assigned','in_progress','pending','resolved','closed'))");
DB::statement('CREATE INDEX tickets_open_pidx ON tickets (tenant_id, priority_score DESC, id) WHERE status NOT IN (\'resolved\',\'closed\')');
```

The schema builder is used for everything it supports (`uuid`, `foreignUuid`, `jsonb`, `timestampTz`, `unique`, `index`); raw SQL is reserved for extensions, generated columns, check constraints, partial/GIN indexes, composite FKs, RLS and grants. Each raw statement has a matching `down()`.

## Reversibility

- Every migration implements `down()`; irreversible ones (data backfills) throw `IrreversibleMigration` with a message naming the restore procedure.
- CI runs `migrate:fresh --force`, then `migrate:rollback --step=1000`, then `migrate` again, on the PostgreSQL service container, to prove both directions.
- `migrate:fresh` is forbidden outside local/CI (`DB::prohibitDestructiveCommands()` in production).

## Roles

Migrations run through the `pgsql_owner` connection (`php artisan migrate --database=pgsql_owner`, wrapped by `just migrate`); the deploy playbook uses the same. Objects are therefore owned by `helpdesk_owner`, and the default privileges grant the app role its rights automatically. A post-migration check (`db:verify-roles`) asserts the app role's grants and `NOBYPASSRLS`.

## Seeders

| Seeder | Content | When |
|---|---|---|
| `BaseSeeder` | permission catalogue, global default roles, platform settings, a platform admin from env | every environment, idempotent (`updateOrCreate`) |
| `ProvisionTenant` (action, not a seeder) | per-tenant defaults: categories, default SLA policy + 4 targets, automation settings, counters, owner user | on tenant creation |
| `DemoSeeder` | two tenants (Acme rich, Globex minimal) with agents, teams, skills, contacts, ~120 tickets in all statuses/priorities, near-breach timers, duplicate clusters, a webhook subscription to `webhook-echo`; deterministic (fixed seed, fixed base timestamp, UUIDs derived from seed) | `just demo-reset` (dev/demo profile) |
| `ExperimentSeeder` | datasets from `experiments/datasets/v1` into an isolated tenant | experiments |

Demo data timestamps are relative to "now" at seed time so SLA states look live; `just demo-tick` advances the frozen demo clock for the presentation ([12-academic/demo-plan.md](../12-academic/demo-plan.md)).

## Data migrations

Data changes (backfills, recomputations) are artisan commands (`tickets:recompute-priority`, `sla:recompute --policy=`), never migrations, so they can be re-run, batched and tenant-iterated. A schema migration that requires a backfill documents the command in its docblock.

## Squashing and history

No squashing during the MVP. After the first tagged release, `schema:dump --prune` may be used once per major version; the pruned migrations are kept in git history.

## Zero downtime

Not an MVP requirement: deploys take a maintenance window (`down --secret`) of under a minute. Additive migrations are still preferred (add column → deploy code → drop old column later) so the V1 move to expand/contract deploys needs no habit change.

## Local workflow

```bash
just migrate            # migrate as owner, then db:verify-roles
just migrate-fresh      # local only: fresh + BaseSeeder
just demo-reset         # fresh + BaseSeeder + DemoSeeder
just make-migration tickets create_ticket_events_table
```
