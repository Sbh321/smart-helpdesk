# Architecture overview

Smart Helpdesk is a **modular monolith**: one Laravel 13 API, one React SPA, PostgreSQL, Valkey, S3-compatible object storage, and worker processes, packaged as Docker images and run by Compose on a workstation, an on-prem server or one cloud VM. Decisions: [ADR index](../adr/README.md).

## System context (C4 level 1)

```mermaid
flowchart LR
    Agent[Support Agent / Manager / Tenant Admin]
    PSA[Platform Super Admin]
    Contact[Contact / Requester]
    Ext[External system\nintegration]
    subgraph SH[Smart Helpdesk]
        SPA[React SPA]
        API[Laravel API]
    end
    Mail[(SMTP / mail provider)]
    Agent -->|HTTPS| SPA --> API
    PSA -->|HTTPS admin host| SPA
    Ext -->|OAuth2 client credentials, REST| API
    API -->|signed webhooks| Ext
    API -->|email| Mail --> Contact
```

## Containers (C4 level 2)

```mermaid
flowchart TB
    subgraph Edge
        Caddy[Caddy: TLS, static SPA, reverse proxy]
    end
    subgraph App[Application plane - one image, several processes]
        App1[app: nginx + PHP-FPM]
        Horizon[horizon: queue workers]
        Sched[scheduler: schedule:work]
        Reverb[reverb: websockets - optional]
    end
    Mail[mail: Stalwart SMTP/IMAP - profile mail]
    App1 & Horizon -->|SMTP 587 / IMAP| Mail
    Mail -->|SMTP 25 or relay| Internet((Internet))
    subgraph Data
        PG[(PostgreSQL 18)]
        VK[(Valkey 9)]
        S3[(S3-compatible storage: RustFS / cloud)]
    end
    Browser --> Caddy
    Caddy -->|/api, /docs, /horizon| App1
    Caddy -->|/app, /apps| Reverb
    App1 & Horizon & Sched --> PG
    App1 & Horizon & Sched & Reverb --> VK
    App1 & Horizon --> S3
    Browser -->|presigned PUT/GET| S3
```

## Backend modules (C4 level 3)

```mermaid
flowchart LR
    Platform --> Tenancy
    Identity --> Tenancy
    Contacts --> Tenancy
    Agents --> Identity
    Tickets --> Contacts & Agents
    Sla --> Tickets
    Automation --> Tickets & Agents & Sla
    Notifications -. listens .-> Tickets & Sla & Automation
    Integrations -. listens .-> Tickets & Contacts
    Reporting -. reads .-> Tickets & Sla & Agents & Contacts & Media & Mail
    Audit -. called by .-> Identity & Platform & Integrations & Sla & Tenancy
    Media --> Tenancy
    Tickets --> Media
    Mail --> Tickets & Contacts & Media
```

Arrows point from dependant to dependency. Dotted arrows are event listeners or read-only queries; they never create import cycles. Details: [backend.md](backend.md).

## Request lifecycle (tenant request)

1. The SPA at `app.shp.subhambhandari.com.np/acme` calls `api.shp.subhambhandari.com.np/v1/...`; Caddy terminates TLS and proxies the `api` host to the `app` container.
2. `ResolveTenantFromPrincipal` (session tenant, API client tenant, or the single-tenant setting) loads the tenant ([ADR-0021](../adr/0021-host-layout-and-tenant-resolution.md)), runs bootstrappers (cache/storage/queue prefixes, `SET app.current_tenant`, `setPermissionsTeamId`).
3. `auth:sanctum,api` authenticates the session or token; `EnsureTenantMembership` asserts `user.tenant_id = tenant.id`.
4. Route → FormRequest (validation + `can:` permission) → Controller → Action/Query → JsonResource.
5. Actions run in a transaction, write domain history/audit, and dispatch events; listeners queue notifications, webhooks and broadcasts with tenant context serialised.
6. `tenancy()->end()` on terminate resets connection settings.

## Cross-cutting concerns and where they live

| Concern | Mechanism | Doc |
|---|---|---|
| Tenant isolation | stancl bootstrappers + global scopes + RLS + tests | [tenancy.md](tenancy.md) |
| Authentication / authorisation | Sanctum, Passport (client credentials), spatie permissions, policies | [security.md](security.md) |
| Configuration layers | code → env → platform settings → tenant settings → flags | [configuration.md](configuration.md) |
| Errors | RFC 9457 problem details, error codes, frontend mapping | [error-handling.md](error-handling.md) |
| Files | S3 disk, presigned URLs, tenant key prefixes, media library | [storage.md](storage.md), [04-domain/media.md](../04-domain/media.md) |
| Email | bundled Stalwart mail server or relay; inbound email-to-ticket | [04-domain/email.md](../04-domain/email.md), [ADR-0018](../adr/0018-mail-server.md) |
| Realtime | broadcast events, polling, Reverb | [realtime.md](realtime.md) |
| Async work | Horizon queues per concern, tenant-aware jobs | [11-operations/queues.md](../11-operations/queues.md) |
| Time | injectable `Clock`, UTC everywhere, `BusinessCalendar` interface | [05-algorithms/sla-evaluation.md](../05-algorithms/sla-evaluation.md) |
| Observability | JSON logs with request/tenant ids, health endpoint, Horizon | [11-operations/observability.md](../11-operations/observability.md) |
| Deployment | Compose profiles, Caddy, OpenTofu, Ansible | [deployment.md](deployment.md) |

## Repository layout

```text
smart-helpdesk/
├── backend/            Laravel 13 application (app/Modules/*)
├── frontend/           React SPA (Vite)
├── infra/
│   ├── compose/        base.yaml, dev.yaml, prod.yaml, demo.yaml
│   ├── caddy/          Caddyfile templates
│   ├── tofu/           modules/, providers/{none,digitalocean,aws,gcp,hetzner}, envs/reference
│   └── ansible/        inventory, playbooks, roles
├── experiments/        datasets/ and results/ for the academic evaluation
├── docs/               this documentation
├── roadmap/            the plan
├── compose.yaml        includes infra/compose/*
├── justfile            task runner
└── .mise.toml          tool versions
```

## Quality attributes (summary)

Targets and how they are met are in [02-product/non-functional-requirements.md](../02-product/non-functional-requirements.md): isolation by construction and tests; p95 list latency < 300 ms at 100k tickets via indexes and server-side paging; background work off the request path; portability by configuration; accessibility via the design system.
