# MVP scope (MoSCoW)

The MVP is what exists at the end of milestone 3 ([roadmap](../../roadmap/README.md)). This page is the single authoritative boundary. If a feature is not listed as Must or Should here, it is not being built in the MVP.

## Must have (the system is not demonstrable without these)

| Feature | Module | Roadmap |
|---|---|---|
| Multi-tenant platform with tenant creation, suspension, workspaces on `app.shp.subhambhandari.com.np` with the tenant fixed at login (single-tenant mode on-prem) | Tenancy | M1 |
| Login, invitations, sessions, roles, granular permissions | Identity | M1 |
| Contacts and organisations with tiers | Contacts | M1 |
| Teams, skills, categories, agent profiles (capacity, availability) | Agents | M1–M2 |
| Ticket CRUD, number, lifecycle state machine, tags | Tickets | M1–M2 |
| Public replies, internal notes, attachments (presigned upload, signed download) | Tickets | M2 |
| Media library: folders, tags, search, image variants, reuse in tickets/branding, quota | Media | M2 |
| Business calendars (time zone, working hours, holidays) applied to SLA and ageing | SLA | M2 |
| Bundled mail server (Stalwart) for DKIM-signed outbound mail with relay option | Mail/Infra | M3 |
| Ticket history timeline | Tickets/Audit | M2 |
| Ticket list: server-side pagination, filters, sort, search | Tickets (UI) | M2 |
| Priority scoring with explanation and settings | Automation | M2 |
| Auto-assignment with explanation, manual assign, settings | Automation | M2 |
| Duplicate detection with suggestions and mark-as-duplicate | Automation | M2 |
| SLA policies, timers, pause/resume, warning, breach, escalation | SLA | M2 |
| In-app + email notifications | Notifications | M2 |
| Dashboard KPIs and six charts | Reporting | M3 |
| Detailed reporting: change capture, report catalogue across all entities, backlog/time-in-status/workload over time, entity 360 with history and as-of view, CSV/XLSX export | Reporting | M1–M3 |
| `/v1` REST for core entities, OAuth2 client credentials, OpenAPI docs at `/docs/api` | Integrations | M3 |
| Webhooks: subscriptions, HMAC signing, retries, delivery log, manual retry | Integrations | M3 |
| Security audit log | Audit | M2–M3 |
| Design system with tokens, light/dark/system theme, accessible components | Frontend | M1 |
| Docker Compose dev + production; Ansible to any VM; optional OpenTofu provider modules | Infra | M1, M3 |
| Deterministic demo seed and reset command | Quality | M3 |
| Tests: unit, feature, tenancy isolation, permissions, algorithms; Playwright golden path; CI | Quality | M1–M3 |
| Algorithm experiments with reproducible datasets and result tables | Academic | M3 |

## Should have (built if the Must list is green by the planned day; otherwise cut to V1 on the same day)

| Feature | Cut trigger |
|---|---|
| Realtime push of ticket updates and notification bell via Laravel Reverb (fallback: TanStack Query polling every 30 s) | Not started by M2 day 5 |
| Inbound email-to-ticket (replies become comments, new mail creates tickets) | Not started by M3 day 3 |
| Agent shift schedules gating auto-assignment | Not started by M2 day 6 |
| Bulk actions in ticket table | Not started by M3 day 2 |
| Saved reports shared with roles | Not started before M3 |
| Custom roles UI (permissions API exists regardless) | Not started by M3 day 2 |
| Password reset | Not started by M3 day 2 |
| Auto-close resolved tickets | Trivial scheduler task; cut only if scheduler slips |
| Idempotency-Key on API ticket creation | Not started by M3 day 3 |
| Audit log viewer UI (data always written) | Not started by M3 day 3 |

## Could have (only if genuinely idle)

Contact page ticket list; agent workload page; ticket watchers; email verification; personal access tokens; per-user notification preferences; column visibility persistence server-side.

## Won't have in the MVP (recorded in the V1 backlog)

Customer portal; live chat; social/WhatsApp/Telegram/Viber/SMS; knowledge base; custom fields; custom statuses; rule builder; business-hour calendars; billing; white-label beyond logo + primary colour; AI features; external search engine; SSO/MFA; mobile apps/push; dedicated tenant databases; high availability; Kubernetes; OpenTelemetry stack; plugin marketplace.

## Size budget

Roughly 60 roadmap tasks across 15 working days plus buffer (see [roadmap/01-dependency-graph.md](../../roadmap/01-dependency-graph.md)). Milestone 3 reserves two days of unassigned buffer; if it is consumed before day 3 of milestone 3, the Should list is cut in the order listed above.
