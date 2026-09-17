# ADR-0006 Multi-tenancy: shared database with `tenant_id`, stancl/tenancy single-DB mode, RLS

**Status:** Accepted (2026-09-17); identification (decision 3) superseded by [ADR-0021](0021-host-layout-and-tenant-resolution.md)

## Context

Requirements: SaaS and on-prem from one codebase, strong isolation, manageable migrations and backups, simple MVP operations, and a path to dedicated databases for large tenants. Options A–D and package comparison: [01-research/multitenancy-options.md](../01-research/multitenancy-options.md).

## Decision

1. **Data model: shared database, shared schema, `tenant_id NOT NULL` on every tenant-owned table** (approach A now, hybrid D later). Control-plane tables (`tenants`, `domains`, platform users, platform audit) have no `tenant_id`.
2. **Package: stancl/tenancy 3.10.x in single-database mode.** `Tenant` model without `HasDatabase`; database bootstrapper disabled; cache, filesystem, queue and Redis bootstrappers enabled; `BelongsToTenant` on primary models and `BelongsToPrimaryModel` on secondary models; a custom bootstrapper sets `app.current_tenant` for RLS and spatie's permission team id.
3. **Identification**: subdomain (`{slug}.{platform-domain}`) for SaaS and local development (`*.helpdesk.localhost`); **single-tenant mode** for on-prem (`TENANCY_SINGLE_TENANT=<slug>` resolves the configured tenant for any host); platform routes on the central domain (`admin.` subdomain). API-client tokens carry the tenant from the client record and must match the host tenant. After authentication, `user.tenant_id === tenant.id` is asserted on every request; this membership check is the security control.
4. **PostgreSQL row-level security** on every tenant table (`ENABLE` + `FORCE`, policy on `current_setting('app.current_tenant', true)::uuid`), runtime role without ownership or `BYPASSRLS`. Roles from milestone 1; policies in milestone 3 (MVP scope, first Should-have priority).
5. **Isolation everywhere**: storage keys `tenants/{id}/…`; cache tags/prefix per tenant; queue jobs carry and re-initialise tenant; broadcast channels `tenants.{id}.…` with tenant-checked authorisation; logs carry `tenant_id`; `notifications` gains `tenant_id`; scheduled commands iterate tenants via `tenants:run` or explicit loops.
6. **Isolation test suite** (reflection over models, HTTP negative tests, schema assertions, queue and channel tests) is mandatory ([10-quality/testing.md](../10-quality/testing.md)).

## Alternatives considered

- Database per tenant from the start: strongest isolation but a week of provisioning/migration/backup work and a harder on-prem story. Deferred to dedicated tenants.
- Schema per tenant: most of C's operational cost plus session-state hazards; rejected.
- spatie/laravel-multitenancy: no scoping trait; equivalent to hand-rolling. Rejected.
- Hand-rolled scopes and middleware: simplest, fully understood, but loses the bootstrapper and migration path the requirements ask for. Rejected (would be the choice if dedicated tenants were not a requirement).
- `X-Tenant` header identification: client-controlled and no cookie separation. Rejected.

## Consequences

- One migration run, cheap cross-tenant admin queries, simple backups.
- We use about a third of stancl/tenancy; the isolation guarantees come from our scopes, tests and RLS, not from the package.
- Wildcard subdomains need wildcard or on-demand TLS at the edge (ADR-0012).
- Every developer must know the primary/secondary model taxonomy; the reflection test enforces it.

## Migration / future considerations

Dedicated tenant: enable `DatabaseTenancyBootstrapper` for tenants flagged `placement = dedicated`, add `HasDatabase`, run `tenants:migrate` for those only; `tenant_id` columns stay (harmless). Control-plane/application-plane split becomes explicit then ([roadmap/10-future-architecture.md](../../roadmap/10-future-architecture.md)).
