# Multi-tenancy options

Researched 2026-09-17. Decision recorded in [ADR-0006](../adr/0006-multi-tenancy-model.md); implementation design in [03-architecture/tenancy.md](../03-architecture/tenancy.md).

## Data-isolation approaches

| | A. Shared DB, shared schema, `tenant_id` | B. Schema per tenant (`search_path`) | C. Database per tenant | D. Hybrid (A by default, C for dedicated tenants) |
|---|---|---|---|---|
| Isolation | Application-level (global scopes) + optional PostgreSQL RLS | Structural per schema | Structural per database | A + RLS for pooled tenants; C for dedicated |
| Migrations | One run | N runs with `search_path` juggling; Laravel migrator has no native support | N runs (`tenants:migrate`); mixed-version risk on partial failure | One run for pooled; N for dedicated |
| Backups / restore per tenant | Logical export by `tenant_id` (must be written) | `pg_dump -n` | `pg_dump` per DB (best) | both |
| Connections | One pool | One pool, but `search_path` is session state (PgBouncer transaction pooling unsafe) | FPM workers × tenants; needs pooler with per-DB pools | mixed |
| Cross-tenant analytics / platform admin | Trivial SQL | Awkward | Fan-out jobs | mixed |
| Catalog growth | none | 200 tenants × 30 tables = 6 000 relations; autovacuum/planner overhead in the low thousands of tenants | N catalogs | bounded |
| Fit for 3-week solo MVP and on-prem Compose | **Best** | Poor (most of C's ops cost, most of A's planner cost) | Poor (provisioning, per-tenant migrations, backup story: ≈ 1 week) | A now, C later |
| Fit for "large dedicated tenants later" | Needs D | — | Yes | **Yes** |

**Chosen: D, implemented as A in the MVP with the seams for C.** Every tenant-owned table carries `tenant_id NOT NULL`; all data access goes through Eloquent with the tenant global scope (never hard-coded `WHERE tenant_id` in business code), so switching a tenant to a dedicated connection later is a bootstrapper flip, not a rewrite. Sources: postgresql.org/docs/17/ddl-rowsecurity.html, tenancyforlaravel.com/docs/v3/single-database-tenancy, v4.tenancyforlaravel.com/single-database-tenancy.

## Packages

| Option | Version | Finding |
|---|---|---|
| stancl/tenancy (repo `archtechx/tenancy`) | **3.10.1** (2026-08-05), MIT, Laravel 10–13, 4.4k stars, 9 open issues, pushed 2026-09-17. **v4 is not released** (master requires PHP ≥ 8.4, docs marked WIP). Package is free; only the SaaS boilerplate/course are paid. | Single-DB mode: `BelongsToTenant` (primary models: auto-fill + global scope) and `BelongsToPrimaryModel` (secondary models scoped through a relation). Docs warn that unconfigured secondary models and raw `DB::` queries leak; v4 adds RLS to fix exactly this. Identification middleware: domain, subdomain, domain-or-subdomain, path, request data (`X-Tenant`). Bootstrappers: database (off in single-DB), cache (tags; needs Redis), filesystem (path suffix / S3 prefix via `root_override`), queue (tenant id in payload, re-init in worker), Redis prefix (phpredis only). Horizon central-only (tag jobs), Telescope central (dev-only for us), Passport docs assume multi-DB. |
| spatie/laravel-multitenancy | 4.2.0 (2026-08-07), MIT | Deliberately minimal: tenant finder + "tasks" on switch; **no scoping trait** for single-DB, so it is hand-rolling plus a resolver. |
| Hand-rolled | — | ~200 lines: trait with global scope + creating hook, resolver middleware, context singleton, tenant-aware job trait. Fully understood; poorer migration path to dedicated databases. |

**Decision: stancl/tenancy 3.10.x in single-database mode.** We use roughly a third of the package (identification, cache/filesystem/queue/Redis bootstrappers, the two traits, `tenants:run`) and keep the option to enable `DatabaseTenancyBootstrapper` for dedicated tenants. spatie gives less for this shape; hand-rolled loses the migration path the requirements ask for. Honest caveat recorded: single-DB mode is the less-travelled path in v3, so the isolation test suite and RLS below are mandatory, not optional.

## Tenant identification for an SPA + API

| Mechanism | Pros | Cons |
|---|---|---|
| Subdomain (`acme.helpdesk.tld`) | Cookie/origin separation per tenant (XSS blast radius), no client-supplied tenant value, matches SaaS expectations | Wildcard DNS + wildcard TLS (fine with DNS-01/Cloudflare; annoying on-prem) |
| `X-Tenant` header / query | No DNS work | Client-supplied; needs a membership assertion every request; no cookie separation |
| Path (`/acme/...`) | No DNS | Ugly URLs, route param everywhere |
| Configured single tenant | On-prem simplicity | Only for single-tenant installs |

**Decision**: subdomain identification for SaaS and local development (`*.helpdesk.localhost` resolves to loopback in browsers without hosts-file edits); a configured single-tenant mode for on-prem (`TENANCY_SINGLE_TENANT=<slug>`); API-client tokens carry the tenant from the client record and must match the host tenant when one is resolved. No `X-Tenant` header. Regardless of mechanism, the authenticated user's `tenant_id` is asserted to equal the resolved tenant on every request; this membership check, not the resolver, is the security control.

## PostgreSQL Row-Level Security as defence in depth

Mechanics (postgresql.org/docs/17/ddl-rowsecurity.html): `ENABLE` + `FORCE ROW LEVEL SECURITY` per tenant table and one policy `USING (tenant_id = current_setting('app.current_tenant', true)::uuid) WITH CHECK (same)`. Superusers, roles with `BYPASSRLS` and table owners (unless `FORCE`) bypass RLS, so the runtime role must be a non-owner without `BYPASSRLS`; migrations run as the owner role. The setting is issued on tenancy initialisation (`SET app.current_tenant`), and on `tenancy()->end()` it is reset. With a missing setting the policy evaluates NULL and returns zero rows (fails closed).

PgBouncer transaction pooling does not support `SET` (pgbouncer.org/features.html), so session-level settings would leak between clients; **the MVP runs without a pooler** (FPM pool of 10–30 workers does not need one), and V1 adopts `SET LOCAL` inside a request transaction if a pooler is introduced.

**Decision**: RLS is in the MVP (milestone 3 hardening, ~1 day), with two database roles created in milestone 1 so nothing has to change later. Per-tenant DB roles, RLS on central tables and pooler-compatible transactions are V1.

## Isolation test suite (mandatory regardless of package)

1. Reflection over all Eloquent models: seed two tenants, assert queries under tenant A never return tenant B rows; a model missing the tenant trait fails CI.
2. HTTP: user of tenant A calling tenant B's host (or B's resource IDs) gets 401/404.
3. Schema assertions from `information_schema`: every tenant table has `tenant_id NOT NULL`, every unique index includes `tenant_id`, RLS enabled and forced on every tenant table, runtime role is not owner and lacks `BYPASSRLS`.
4. Queue: a job dispatched in tenant A context runs with tenant A initialised on the worker; a job dispatched centrally has no tenant.
5. Broadcast channel authorisation denies cross-tenant subscriptions; presigned upload keys are server-generated under the tenant prefix.
6. The `notifications` table (polymorphic, no `tenant_id` by default) gets a `tenant_id` column and is included in test 1.

## Sources

tenancyforlaravel.com/docs/v3 (single-database-tenancy, tenant-identification, tenancy-bootstrappers, queues, console-commands, configuration, integrations/{horizon,telescope,passport,spatie}, package-comparison); v4.tenancyforlaravel.com; github.com/archtechx/tenancy; packagist.org/packages/stancl/tenancy; spatie.be/docs/laravel-multitenancy/v4; postgresql.org/docs/17 (ddl-rowsecurity, sql-set); pgbouncer.org/features.html.
