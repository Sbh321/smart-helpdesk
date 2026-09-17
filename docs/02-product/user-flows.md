# User flows

Flows are drawn as activity/sequence diagrams that the report reuses. Each flow names the API endpoints it exercises so the E2E tests and the docs stay aligned.

## F1 — Tenant onboarding (SaaS)

```mermaid
sequenceDiagram
    actor PSA as Platform Super Admin
    participant API as Laravel API (central)
    participant DB as PostgreSQL
    participant Mail as Mail
    PSA->>API: POST /platform-api/tenants {name, slug, owner_email}
    API->>DB: insert tenant, default roles, categories, SLA policy, settings
    API->>DB: insert owner user (unverified)
    API->>Mail: invitation with signed set-password URL
    API-->>PSA: 201 tenant
    Note over API: tenant reachable at app.shp.subhambhandari.com.np/<slug>
```

## F2 — Login and tenant resolution (SPA)

```mermaid
sequenceDiagram
    actor U as User
    participant SPA as React SPA
    participant API as Laravel API
    U->>SPA: open app.shp.subhambhandari.com.np/acme
    SPA->>API: GET /sanctum/csrf-cookie
    SPA->>API: POST api.shp…/v1/auth/login {workspace: acme, email, password}
    API->>API: find workspace (or configured single tenant on-prem), verify credentials within it
    API->>API: store tenant_id in the session
    API-->>SPA: 200 {user, tenant, permissions}
    SPA->>API: GET /v1/me (session cookie)
    API-->>SPA: user + permissions + tenant settings
```

Details and the on-prem variant are in [03-architecture/tenancy.md](../03-architecture/tenancy.md) and [07-api/authentication.md](../07-api/authentication.md).

## F3 — Golden path: ticket creation with automation

```mermaid
sequenceDiagram
    actor A as Agent
    participant SPA
    participant API
    participant PR as PriorityStrategy
    participant DD as DuplicateStrategy
    participant AS as AssignmentStrategy
    participant SLA as SlaStrategy
    participant Q as Queue
    A->>SPA: fill ticket form
    SPA->>API: POST /v1/tickets/preview-duplicates {title, description, category_id}
    API->>DD: candidates (top 50 by trigram) → Jaccard score
    API-->>SPA: suggestions [{ticket, score, breakdown}]
    A->>SPA: proceed (or mark duplicate)
    SPA->>API: POST /v1/tickets {...}
    API->>PR: score(ticket, settings) → {score, level, breakdown}
    API->>SLA: start timers(policy, priority)
    API->>DD: store suggestions
    API->>AS: assign(ticket) → {agent, breakdown}
    API-->>SPA: 201 ticket (with explanations)
    API->>Q: TicketCreated → notifications, webhooks
```

## F4 — Agent works the ticket

Open ticket → read duplicate suggestions and SLA panel → add internal note → public reply (satisfies first response; email to contact) → set `in_progress` → set `pending` (SLA paused) → contact reply recorded by agent → `in_progress` (SLA resumed) → `resolved` with resolution comment → auto-close after N days or manual close → optional reopen.

## F5 — SLA warning and breach

```mermaid
sequenceDiagram
    participant Sched as Scheduler (every minute)
    participant SLA as SlaStrategy
    participant DB
    participant N as Notifications
    participant WH as Webhooks
    Sched->>SLA: evaluate(now)
    SLA->>DB: select timers where state=running and (warning_at<=now or due_at<=now)
    loop each timer
        SLA->>SLA: transition running→warning | warning→breached
        SLA->>DB: update timer, insert sla_event, ticket history
        SLA->>N: notify assignee (warning) / manager (breach)
        SLA->>WH: ticket.sla_breached (breach only)
    end
```

## F6 — Integration developer

Create OAuth2 client in Settings → `POST /oauth/token` (client_credentials) → `POST /v1/tickets` with bearer token → register webhook → receive `ticket.status_changed` with `X-Helpdesk-Signature` → verify HMAC → view delivery log and retry from the UI.

## F7 — Dashboard and export

Manager opens Dashboard → KPI tiles and charts load from `/v1/dashboard` → clicks "Export tickets CSV" → job enqueued → in-app notification with signed download link.

## F8 — Tenant isolation (negative flow, tested)

User of Tenant A requests `/v1/tickets/{uuid of tenant B ticket}` → 404 (not 403, to avoid existence disclosure). Same for attachments, contacts, comments, webhook logs, analytics, and search. Broadcast channel `tenant.{B}.tickets` authorisation denied. Signed URL for B's attachment not obtainable via A's session.
