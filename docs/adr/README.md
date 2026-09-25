# Architecture Decision Records

Format for every ADR: **Status · Context · Decision · Alternatives Considered · Consequences · Migration/Future Considerations.** Status is one of Proposed, Accepted, Superseded (by ADR-n). Numbering is chronological; an ADR is never edited after acceptance except to change its status; a new ADR supersedes it.

Documentation that contradicts an accepted ADR is a bug ([principles](../00-project/principles.md) P10).

| ADR | Title | Status |
|---|---|---|
| [0001](0001-frontend-architecture.md) | Frontend architecture: React SPA on Vite with the TanStack stack | Accepted |
| [0002](0002-ui-primitive-strategy.md) | UI primitives: shadcn/ui on Base UI with Tailwind v4 tokens | Accepted |
| [0003](0003-state-management.md) | State management: server cache, URL and context; no global store | Accepted |
| [0004](0004-modular-monolith.md) | Backend: Laravel modular monolith with actions and domain services | Accepted |
| [0005](0005-postgresql.md) | PostgreSQL 18 as the only database | Accepted |
| [0006](0006-multi-tenancy-model.md) | Multi-tenancy: shared database with `tenant_id`, stancl/tenancy single-DB mode, RLS | Accepted |
| [0007](0007-authentication-and-oauth.md) | Authentication: Sanctum sessions for the SPA, Passport client credentials for integrations, spatie permissions | Accepted |
| [0008](0008-object-storage.md) | Object storage: S3-compatible disk; RustFS instead of MinIO for self-hosting | Accepted |
| [0009](0009-realtime-transport.md) | Realtime: broadcast events with polling first, Laravel Reverb when enabled | Accepted |
| [0010](0010-api-documentation.md) | API documentation: Scramble-generated OpenAPI 3.1 | Accepted |
| [0011](0011-search-architecture.md) | Search: PostgreSQL full-text and trigram only | Accepted |
| [0012](0012-deployment-architecture.md) | Deployment: Docker Compose everywhere, Caddy edge, OpenTofu + Ansible for one VM | Accepted |
| [0013](0013-identifiers.md) | Identifiers: UUID v7 primary keys, tenant-scoped ticket numbers | Accepted |
| [0014](0014-cache-queue-infrastructure.md) | Cache and queues: Valkey with phpredis, Horizon | Accepted |
| [0015](0015-audit-strategy.md) | Audit: purpose-built tables instead of an activity-log package | Accepted |
| [0016](0016-testing-strategy.md) | Testing: Pest, Vitest browser mode, Playwright with axe | Accepted |
| [0017](0017-cloud-agnostic-deployment.md) | Cloud-agnostic deployment targets: Ansible on any VM, optional OpenTofu providers | Accepted |
| [0018](0018-mail-server.md) | Mail: bundled self-hosted mail server with relay option; inbound email-to-ticket | Accepted |
| [0019](0019-media-library.md) | Media library module | Accepted |
| [0020](0020-business-calendars.md) | Business-hours calendars and agent shifts in the MVP | Accepted |
| [0021](0021-host-layout-and-tenant-resolution.md) | Host layout on `shp.subhambhandari.com.np`; tenant from session or client | Accepted |
| [0022](0022-reporting-and-history.md) | Reporting module: change capture, derived read models, report catalogue | Accepted |
| [0023](0023-minimal-replaceable-algorithms.md) | Minimal academic baseline algorithms behind replaceable strategies | Accepted |
| [0024](0024-platform-docs-host.md) | Platform documentation host behind the platform sign-in | Accepted |
