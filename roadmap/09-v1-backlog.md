# V1 backlog

Everything here is deliberately **not** in the MVP ([mvp-scope.md](../docs/02-product/mvp-scope.md)). Each item names the MVP seam it builds on so the MVP architecture is judged by whether these remain additive. Sizes: S ≤ 2 days, M ≤ 1 week, L ≤ 3 weeks, XL longer. Ordering inside a theme is by expected value.

## Customer-facing channels

| ID | Item | Description | Why deferred | MVP seam | Size |
|---|---|---|---|---|---|
| V1-CH-01 | Customer portal | Contacts log in (magic link / password), see their tickets, reply, attach files | needs a third auth audience and public UI | contacts model, `public` comment visibility, `contact` author type, contact email notifications | L |
| V1-CH-03 | Live chat widget | embeddable widget creating tickets/conversations, agent chat view | realtime + second client | Reverb channels, contacts, tickets | XL |
| V1-CH-04 | Social and messaging channels: WhatsApp, Telegram, Viber, SMS | channel adapters mapping messages to ticket comments | provider accounts and compliance | `created_via`, integrations module, webhooks inbound | XL |
| V1-CH-05 | Knowledge base | articles, categories, public site, agent suggestions | separate content domain | tenant branding tokens, FTS scopes | L |
| V1-CH-06 | Web push / mobile notifications | VAPID web push; later native apps | desktop agents have the tab open; not for air-gapped on-prem | notification classes with `via()`; `notification_preferences` JSONB hook | M |

## Ticket domain

| ID | Item | Description | Why deferred | MVP seam | Size |
|---|---|---|---|---|---|
| V1-TK-01 | Custom fields | per-tenant field definitions, JSONB values, filters, API exposure | schema/UI generality | tenant settings JSONB pattern, `metadata` columns | L |
| V1-TK-02 | Custom statuses and workflows | tenant-defined statuses mapped to the six canonical states | SLA and analytics depend on canonical states | `TicketStatus` transition table designed as data-capable | L |
| V1-TK-03 | Automation rule builder | if/then rules on events (set field, assign, notify, webhook) | generic engine before specific needs | domain events, actions callable from listeners | L |
| V1-TK-04 | Macros / canned responses | reusable replies with placeholders | convenience | comments API | S |
| V1-TK-05 | Watchers / CC | users following tickets | Could-have | notifications fan-out | S |
| V1-TK-06 | Contact merge | merge duplicates, re-point tickets | rare in MVP data | contacts model, ticket FK | S |
| V1-TK-07 | Ticket merge and linking (parent/child, related) | beyond `duplicate_of` | UI complexity | `duplicate_of_id` pattern | M |
| V1-TK-08 | Saved views and shared filters | persist search-param sets server-side | column visibility is client-side in MVP | URL-as-state design; `users.preferences` | S |

## SLA and automation

| ID | Item | Description | Why deferred | MVP seam | Size |
|---|---|---|---|---|---|
| V1-SL-02 | Escalation levels with timeouts (PagerDuty model) | ordered levels, acknowledgement, repeat | action list suffices in MVP | escalation JSON per policy, idempotent action runner | M |
| V1-SL-03 | Next-reply and periodic-update timers | Zendesk-style additional metrics | two timers suffice | timer `kind` enum, sweep query | M |
| V1-SL-04 | Hungarian batch assignment | per-shift optimal rebalancing | optional academic extra | `AssignmentStrategy` contract; scoring matrix, experiment harness | S |
| V1-SL-05 | Shift schedules and workforce management | availability from rosters, forecasting | out of scope | `availability` enum, capacity | XL |
| V1-SL-06 | Learned skill proficiency | derive levels from resolution outcomes | needs data | skill levels (not in the MVP) 1–3 | M |

## AI and search

| ID | Item | Description | Why deferred | MVP seam | Size |
|---|---|---|---|---|---|
| V1-AI-01 | Semantic duplicate detection | pgvector embeddings via Laravel AI SDK as a `DuplicateStrategy` replacement or hybrid (learned REP weights) | MVP must be our own explainable algorithm | `DuplicateDetector` channel fusion, `ticket_text_features`, PostgreSQL | M |
| V1-AI-02 | Ticket summaries and suggested replies | LLM-generated, agent-approved | external AI dependency; not for air-gapped | comments API, `Laravel\Ai` | M |
| V1-AI-03 | AI categorisation and priority suggestion | suggestion layer above the deterministic score | explainability first | priority explanation UI shows both | M |
| V1-AI-04 | MinHash/LSH candidate retrieval | scale beyond K-bounded SQL pre-filter | not needed below ~100k tickets/tenant | pipeline stage A is isolated | M |
| V1-AI-05 | External search engine (Meilisearch via Scout) | instant search, typo tolerance, facets | second isolation boundary; on-prem service | `Ticket::search()` scope is the seam; Scout driver | M |
| V1-AI-06 | Multilingual stemming and stop words | non-English tenants | English MVP | `WordSet` stop-word file and a future stemming step; interfaces | S |

## Analytics and reporting

| ID | Item | Description | Why deferred | MVP seam | Size |
|---|---|---|---|---|---|
| V1-AN-01 | Custom reports and scheduled reports | report builder, email schedules | BI creep | analytics queries, export job | L |
| V1-AN-02 | Async export for any size, XLSX | MVP export is CSV, bounded | | export job + storage | S |
| V1-AN-03 | Agent performance and CSAT | surveys, per-agent KPIs | survey channel needed | comments, resolution timestamps | M |
| V1-AN-04 | Business-hours-aware averages | exclude paused/non-business time | MVP reports wall-clock | timers' `paused_total`, calendar | S |

## Platform, tenancy and operations

| ID | Item | Description | Why deferred | MVP seam | Size |
|---|---|---|---|---|---|
| V1-PL-01 | Dedicated tenant databases (placement) | `placement = dedicated`, stancl `DatabaseTenancyBootstrapper`, per-tenant migrations/backups | MVP scale | `tenant_id` everywhere, Eloquent-only access, stancl already installed | L |
| V1-PL-02 | Control-plane / application-plane split | separate central DB and admin app | single DB suffices | central vs tenant routes/tables already separated | L |
| V1-PL-03 | Billing and subscriptions, plan limits | Stripe/Paddle, seat counts, feature gating via Pennant | no paying tenants yet | platform settings, feature flags in tenant settings | L |
| V1-PL-04 | White labelling | custom domains with TLS, full theme override, email templates per tenant | logo + primary colour in MVP | token architecture, `domains` table, Caddy on-demand TLS | M |
| V1-PL-05 | High availability | multiple app replicas, managed PostgreSQL, Valkey Sentinel, Reverb scaling | single VM MVP | stateless app image, Reverb Redis scaling flag | L |
| V1-PL-06 | Kubernetes packaging (Helm) | only past the thresholds in [10-future-architecture.md](10-future-architecture.md) | Compose suffices | images, health endpoints | M |
| V1-PL-07 | OpenTelemetry / Grafana stack | traces, metrics, Loki, Tempo | JSON logs + health suffice; PHP auto-instrumentation beta | request ids, JSON logs | M |
| V1-PL-08 | Tenant hard-delete and data export (GDPR) | erase or export a tenant's data | soft archive in MVP | `tenant_id` prefixing of storage and rows | M |
| V1-PL-09 | Backup restore automation and PITR | scripted restore, WAL archiving | manual rehearsal in MVP | spatie backup + pg_dump timer | M |
| V1-PL-10 | PgBouncer with `SET LOCAL` request transactions | connection pooling | not needed at MVP scale | RLS setting design allows both | S |
| V1-PL-11 | Partitioning of `ticket_events`, `audit_logs`, `webhook_deliveries` | monthly partitions | volumes small | append-only tables with `created_at` | S |
| V1-PL-12 | Laravel Pulse / Nightwatch | production metrics | Horizon + health suffice | — | S |

## Security and identity

| ID | Item | Description | Why deferred | MVP seam | Size |
|---|---|---|---|---|---|
| V1-SE-01 | SSO (OIDC/SAML) and MFA/passkeys | enterprise login | Fortify/passkeys later | Sanctum session layer; user table | M |
| V1-SE-02 | Authorization-code + PKCE OAuth for third-party apps | user-delegated apps, consent screen | client credentials suffice | Passport installed; scopes = permissions | M |
| V1-SE-03 | Personal access tokens | per-user scripts | Could-have | Sanctum/Passport personal clients | S |
| V1-SE-04 | Malware scanning of uploads (ClamAV job) | scan on complete | infra | attachment `state` machine | S |
| V1-SE-05 | IP allow-lists, session management UI, audit retention/export | enterprise controls | | audit_logs, sessions table | M |
| V1-SE-06 | Field-level encryption for contact PII | compliance | | encrypted casts | S |

## Developer platform

| ID | Item | Description | Why deferred | MVP seam | Size |
|---|---|---|---|---|---|
| V1-DP-01 | Inbound webhooks / generic ingestion endpoint | create tickets from arbitrary payload with mapping | | API, contacts auto-create | M |
| V1-DP-02 | Plugin ecosystem and marketplace | sandboxed extensions | far future | events, API | XL |
| V1-DP-03 | SDKs (TypeScript, PHP) generated from OpenAPI | | | Scramble document | S |
| V1-DP-04 | API usage analytics and per-client quotas | | | rate-limit keys per client | S |
| V1-DP-05 | Event outbox with exactly-once delivery guarantees | | at-least-once with idempotency keys in MVP | `webhook_deliveries` | M |

## Frontend and DX

| ID | Item | Description | Why deferred | MVP seam | Size |
|---|---|---|---|---|---|
| V1-FE-01 | i18n and RTL | react-i18next, locale switch | English MVP | `copy/en.ts`, `Intl`, logical CSS properties | M |
| V1-FE-02 | Storybook and published shadcn registry | component catalogue, design-system distribution | one developer | owned `components/ui`, tokens | M |
| V1-FE-03 | Mobile-responsive layouts | phone layouts for agents | desktop MVP | tokens, container queries | M |
| V1-FE-04 | Virtualised long threads and feeds | TanStack Virtual | pages are small | DataTable abstraction | S |
| V1-FE-05 | Per-user notification preferences | channel matrix | static channels in MVP | `notification_preferences` JSONB | S |
| V1-FE-06 | View transitions and Activity-based detail panes | polish | | React 19.3 | S |

## Cut from MVP during execution

Filled by the weekly risk review ([07-risk-register.md](07-risk-register.md)) and `just shortcuts`. Format: date · item · reason · marker/task · size.

| Date | Item | Reason | Marker / task | Size |
|---|---|---|---|---|
| — | — | — | — | — |

## Moved into the MVP on 2026-09-17

- Email-to-ticket and reply-by-email → M3-19 (Should) via [ADR-0018](../docs/adr/0018-mail-server.md).
- Business-hour calendars → M2-03 via [ADR-0020](../docs/adr/0020-business-calendars.md).

## Added to V1 during milestone 1 (2026-09-18)

Items referenced by `MVP-SHORTCUT` markers written while building milestone 1.

| ID | Item | Why deferred | Seam | Size |
|---|---|---|---|---|
| V1-PL-13 | Platform console SPA on the admin host | the platform API, guard and `platform:*` commands cover the MVP | `/platform-api/*`, `platform` guard, `_platform` route | M |
| V1-FE-07 | Server-side option search in list filters (organisations, tags) | filters load the first 100 options once | `GET /v1/organizations?search=`, `EntityCombobox` | S |
| V1-FE-08 | "Load older" paging on ticket history | the detail page shows the newest 50 entries | `GET /v1/tickets/{id}/history?cursor=` | S |

## Added to V1 during milestone 2 (2026-09-21)

Items referenced by `MVP-SHORTCUT` markers written while building milestone 2.

| ID | Item | Why deferred | Seam | Size |
|---|---|---|---|---|
| V1-PL-14 | `oklch()` brand colours | hex covers the settings form and the contrast check; the token page allows oklch | `BrandingSection` rules, `lib/theme/brand.ts` | S |
| V1-NT-01 | Notification digests and backlog suppression | a breach backlog (scheduler down, bulk import, back-dated sample data) mails every manager once per timer; the MVP matrix sends each event on its own | `SendTicketNotifications`, `notification_key`, `notifications` queue | S |
| V1-NT-02 | Workspace logo in notification and reply mail | the logo needs a public, non-expiring URL | `TicketNotification::toMail`, M3-18 mail identity, Branding folder | S |

## Added to V1 on 2026-09-17 (second round)

| ID | Item | Why deferred | Seam | Size |
|---|---|---|---|---|
| V1-PL-10 | Custom tenant domains (`support.acme.com`) with on-demand TLS | the fixed host layout covers the MVP | `domains` table, Caddy `on_demand_tls` | M |
| V1-PL-11 | Multi-workspace user accounts with a workspace switcher | one user per tenant in the MVP | session `tenant_id` rewrite after a membership check | M |
| V1-RP-01 | Free-form report builder and dashboards per user | catalogue covers the requirement | `ReportDefinition` allow-lists | L |
| V1-RP-02 | Scheduled report emails, if not built as a Could-have | | `saved_reports.schedule` | S |
| V1-RP-03 | Monthly partitioning of `entity_changes` and interval tables; external analytics store | volume | trigger writes, `reports:rebuild` | L |
| V1-RP-04 | Server-side PDF rendering of reports | print stylesheet covers the MVP | report page layout | M |

## Added to V1 on 2026-09-17

| ID | Item | Why deferred | Seam | Size |
|---|---|---|---|---|
| V1-ML-01 | Verified tenant sending domains with per-tenant DKIM | DNS verification flow and key management | `Mail` sender identity | M |
| V1-ML-02 | Instant inbound via Stalwart MTA hooks instead of IMAP polling | polling is simpler and adequate | inbound driver setting | S |
| V1-ML-03 | Bounce/complaint processing and suppression list | needs DSN parsing | `inbound_emails` states | M |
| V1-MD-01 | Responsive images, image editing, video transcoding, CDN | not needed for helpdesk MVP | `variants` JSONB | L |
| V1-MD-02 | Malware scanning of uploads (ClamAV job) | extra service | media `state` | M |
| V1-SL-02 | Rotating rosters, leave, on-call escalation chains | workforce management | `agent_shifts` | L |

## Algorithm replacements after the defence (ADR-0023)

| ID | Replace | With | Evaluate with | Size |
|---|---|---|---|---|
| V1-AL-01 | Basic Weighted Priority | advanced multi-factor score with SLA proximity and hysteresis ([design](../docs/05-algorithms/future/priority-scoring-advanced.md)), or a rule engine, or a ranking learned from overrides | E3 + override agreement | M |
| V1-AL-02 | Least-Loaded Eligible Agent | weighted scoring with skill levels and affinity ([design](../docs/05-algorithms/future/agent-assignment-advanced.md)); batch optimisation (Hungarian / OR-Tools) | E1 | M |
| V1-AL-03 | Jaccard Duplicate Check | TF-IDF/BM25 with stemming ([design](../docs/05-algorithms/future/duplicate-detection-advanced.md)); pgvector embeddings; hybrid with a learned threshold | E2 + production acceptance rate (RPT-T10) | M |
| V1-AL-04 | Simple SLA Timer | per-metric pause rules, recompute modes, next-reply and periodic-update metrics, escalation chains ([design](../docs/05-algorithms/future/sla-evaluation-advanced.md)) | E4 extended | M |
| V1-AL-05 | Baseline removal | delete baseline classes and their settings one release after each replacement | contract suites | S |

