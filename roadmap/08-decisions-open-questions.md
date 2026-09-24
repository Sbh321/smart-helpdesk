# Decisions taken and open questions

## Decisions taken during planning (with the record)

| # | Decision | Record |
|---|---|---|
| D1 | Laravel 13 / PHP 8.5 API-only backend, React 19 / Vite 8 / TS 7 SPA | [versions.md](../docs/01-research/versions.md), [ADR-0001](../docs/adr/0001-frontend-architecture.md) |
| D2 | shadcn/ui on Base UI, Tailwind 4 token architecture, one primitive layer | [ADR-0002](../docs/adr/0002-ui-primitive-strategy.md) |
| D3 | No global state library; Query + URL + context | [ADR-0003](../docs/adr/0003-state-management.md) |
| D4 | Modular monolith with module namespaces, Actions, Domain services; no repositories | [ADR-0004](../docs/adr/0004-modular-monolith.md) |
| D5 | PostgreSQL 18 only; UUID v7 keys; tenant-scoped ticket numbers | [ADR-0005](../docs/adr/0005-postgresql.md), [ADR-0013](../docs/adr/0013-identifiers.md) |
| D6 | Shared-database tenancy with stancl/tenancy single-DB mode, subdomain resolution, single-tenant mode for on-prem, RLS in MVP | [ADR-0006](../docs/adr/0006-multi-tenancy-model.md) |
| D7 | Sanctum sessions for the SPA; Passport client credentials (time-boxed) for integrations; spatie permissions with tenant teams; permission-only checks | [ADR-0007](../docs/adr/0007-authentication-and-oauth.md) |
| D8 | S3-compatible storage only; RustFS (not MinIO) for dev/on-prem; presigned direct uploads | [ADR-0008](../docs/adr/0008-object-storage.md) |
| D9 | Broadcast events from day one; polling in MVP; Reverb as Should-have | [ADR-0009](../docs/adr/0009-realtime-transport.md) |
| D10 | Scramble for OpenAPI at `/docs/api` | [ADR-0010](../docs/adr/0010-api-documentation.md) |
| D11 | PostgreSQL FTS + trigram; no search engine; no Scout | [ADR-0011](../docs/adr/0011-search-architecture.md) |
| D12 | Compose everywhere; serversideup PHP image; Caddy; Ansible on any VM; optional OpenTofu providers | [ADR-0012](../docs/adr/0012-deployment-architecture.md), [ADR-0017](../docs/adr/0017-cloud-agnostic-deployment.md) |
| D13 | Valkey + phpredis; Horizon | [ADR-0014](../docs/adr/0014-cache-queue-infrastructure.md) |
| D14 | Custom audit tables, no activity-log package | [ADR-0015](../docs/adr/0015-audit-strategy.md) |
| D15 | Pest 5, Vitest 5 browser mode, Playwright + axe; no Storybook | [ADR-0016](../docs/adr/0016-testing-strategy.md) |
| D16 | Ticket statuses: `new` merged into `open`; `pending` added; reopen is an event | [tickets.md](../docs/04-domain/tickets.md) |
| D17 | Priority = Basic Weighted Priority (impact, urgency, tier, age; thresholds to P1–P4) — academic baseline | [ADR-0023](../docs/adr/0023-minimal-replaceable-algorithms.md) |
| D18 | Assignment = Least-Loaded Eligible Agent with round-robin tie-break — academic baseline | [ADR-0023](../docs/adr/0023-minimal-replaceable-algorithms.md) |
| D19 | Duplicates = Jaccard word-overlap check with a threshold — academic baseline | [ADR-0023](../docs/adr/0023-minimal-replaceable-algorithms.md) |
| D20 | SLA = Simple SLA Timer on 24×7 or working-hours calendars — academic baseline | [ADR-0023](../docs/adr/0023-minimal-replaceable-algorithms.md) |
| D21 | Calendar model: effort-based tasks on parallel tracks, dates computed from `schedule.yaml`, cut list | [12-schedule.md](12-schedule.md) |
| D22 | Build order on track A in milestone 2: SLA → priority → assignment → duplicates | [03-week-2-product.md](03-week-2-product.md) |

| D23 | RustFS confirmed for dev and self-hosted storage (owner, 2026-09-17) | [ADR-0008](../docs/adr/0008-object-storage.md) |
| D24 | Media library module owns all files; attachments are media items | [ADR-0019](../docs/adr/0019-media-library.md) |
| D25 | Bundled Stalwart mail server with relay option; inbound email-to-ticket as Should-have | [ADR-0018](../docs/adr/0018-mail-server.md) |
| D26 | Hosting on any VM; Ansible is the tested path; OpenTofu providers optional | [ADR-0017](../docs/adr/0017-cloud-agnostic-deployment.md) |
| D27 | Tenant business calendars in the MVP; agent shifts as Should-have | [ADR-0020](../docs/adr/0020-business-calendars.md) |
| D28 | Integrations use Passport client credentials, time-boxed, Sanctum-token fallback (owner asked for a recommendation) | [ADR-0007](../docs/adr/0007-authentication-and-oauth.md) |
| D29 | The Project III report (DOCX) is generated from `report/`, separate from the platform docs | [report-generation.md](../docs/12-academic/report-generation.md) |
| D30 | Licence postponed to a future version | — |
| D31 | Ticket statuses confirmed: one `open` status with a "New" badge; `pending` added (owner, 2026-09-17) | [tickets.md](../docs/04-domain/tickets.md) |
| D32 | Platform domain `shp.subhambhandari.com.np` with `app`, `api`, `admin`, `monitor`, `docs`, `files`, `mail` hosts; tenants are workspaces in the app path; tenant fixed at login | [ADR-0021](../docs/adr/0021-host-layout-and-tenant-resolution.md) |
| D33 | The owner is the only human developer; coding agents run the parallel tracks under owner review | [12-schedule.md](12-schedule.md) |
| D34 | Report personal details stay as placeholders for now | `report/` |
| D35 | Detailed reporting module: database change capture, derived read models, report catalogue for every entity, entity 360 with as-of history, CSV/XLSX export | [ADR-0022](../docs/adr/0022-reporting-and-history.md) |
| D37 | All four algorithms sit behind replaceable strategy contracts and are deprecated after the defence | [ADR-0023](../docs/adr/0023-minimal-replaceable-algorithms.md) |
| D36 | Sprint schedule: whole roadmap in about one week of continuous sessions (4 tracks, 16 h/day, every day) | [schedule.yaml](schedule.yaml) |

## Open questions requiring the owner's input

Answered on 2026-09-17: Q1–Q7, Q10, Q11, Q16 (placeholders for now) and Q17 (solo developer). Defaults hold for the rest until answered.

| # | Question | Default assumed | Affects |
|---|---|---|---|
| Q8 | Bleeding-edge versions (Vite 8, TS 7, Table v9, RustFS 1.0) with documented fallbacks, or conservative versions? | bleeding edge with fallbacks | versions.md |
| Q9 | Reverb realtime: first on the cut list, or a Must for demo impact? | cut list (Should) | M3-16 |
| Q12 | Promote any academic extra (Hungarian batch assignment, AHP weight elicitation) to Must? | none | M3-10 |
| Q13 | Cloud relay provider when port 25 is blocked (SES, Postmark, Resend, host SMTP) | decided per deployment | M3-18 |
| Q14 | Comment body: limited Markdown (planned) or plain text only? | Markdown subset | M2-07 |
| Q15 | Platform-admin impersonation of tenants in the MVP? | no (V1 with audit) | Platform module |
| Q18 | Which DNS provider manages `subhambhandari.com.np`, and can the hosting provider set a PTR record and open port 25 for `mail.shp`? If not, which relay should outbound mail use? | relay when port 25 is blocked | M3-18, production DNS |
| Q19 | Should saved reports be shareable with individual users as well as roles, and are scheduled report emails wanted in this build? | roles only; scheduling is Could | M3-20 |

## Decisions to revisit at fixed points

See [12-schedule.md](12-schedule.md) §Decision points.
