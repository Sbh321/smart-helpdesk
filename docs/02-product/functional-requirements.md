# Functional requirements

Requirements are grouped by module. Each has an ID (stable for traceability in tests and the report), a MoSCoW class (**M**ust / **S**hould / **C**ould / **W**on't-in-MVP) and the use case it derives from. Every **M** requirement has at least one roadmap task and one automated test.

## Tenancy (TEN)

| ID | Requirement | Class | UC |
|---|---|---|---|
| FR-TEN-01 | The platform supports multiple tenants in one installation; each tenant has a unique slug and optional custom domain | M | UC-01 |
| FR-TEN-02 | Tenant is chosen by workspace slug at login and then taken from the session or API client on every request (SaaS), or from the configured single tenant (on-prem); no client-supplied tenant value is trusted after login | M | F2 |
| FR-TEN-03 | All tenant-owned records carry `tenant_id` and are automatically scoped in queries | M | — |
| FR-TEN-04 | Cross-tenant access by ID returns 404 | M | F8 |
| FR-TEN-05 | Storage paths, cache keys, queue jobs, broadcast channels and logs carry tenant context | M | — |
| FR-TEN-06 | Platform super admin can create, suspend and reactivate tenants | M | UC-01 |
| FR-TEN-07 | Tenant creation seeds default roles, categories, SLA policy and settings | M | UC-01 |
| FR-TEN-08 | A tenant can be placed on a dedicated database | W | — |

## Identity and access (IAM)

| ID | Requirement | Class | UC |
|---|---|---|---|
| FR-IAM-01 | Users log in with email + password; session-cookie authentication for the SPA | M | F2 |
| FR-IAM-02 | Passwords hashed with bcrypt/argon2; login rate limited; lockout after repeated failures | M | F2 |
| FR-IAM-03 | Users are invited by email and set their password via a signed, expiring link | M | UC-02 |
| FR-IAM-04 | Roles are tenant-scoped bundles of granular permissions; default roles: Tenant Owner, Tenant Admin, Support Manager, Support Agent, Developer | M | UC-02 |
| FR-IAM-05 | Every API endpoint is guarded by a named permission | M | — |
| FR-IAM-06 | Custom roles can be created by Tenant Admins | S | UC-02 |
| FR-IAM-07 | Password reset by email | S | — |
| FR-IAM-08 | Email verification | C | — |
| FR-IAM-09 | SSO (OIDC/SAML), MFA | W | — |

## Contacts (CON)

| ID | Requirement | Class | UC |
|---|---|---|---|
| FR-CON-01 | CRUD contacts: name, email (unique per tenant), phone, organisation, tags, external identifiers, metadata | M | UC-30 |
| FR-CON-02 | CRUD organisations: name, domain, tier, tags, metadata | M | UC-30 |
| FR-CON-03 | Search contacts by name/email; list with pagination | M | UC-30 |
| FR-CON-04 | Contact page shows the contact's tickets | S | UC-30 |
| FR-CON-05 | Merge contacts | W | — |

## Tickets (TKT)

| ID | Requirement | Class | UC |
|---|---|---|---|
| FR-TKT-01 | Create ticket with title, description (Markdown-safe text), contact, category, impact, urgency, tags, attachments | M | UC-10 |
| FR-TKT-02 | Tenant-scoped sequential ticket number | M | UC-10 |
| FR-TKT-03 | Status lifecycle `open → assigned → in_progress → pending → resolved → closed` with reopen, enforced server-side | M | UC-15 |
| FR-TKT-04 | Public replies and internal notes, distinguished in storage, API and UI | M | UC-12 |
| FR-TKT-05 | Attachments via presigned direct upload, MIME/size validation, signed downloads | M | UC-13 |
| FR-TKT-06 | Ticket history records status, priority, assignment and SLA events with actor and reason | M | UC-18 |
| FR-TKT-07 | List tickets with server-side pagination, filters, sorting and text search | M | UC-11 |
| FR-TKT-08 | Bulk assign and bulk status change | S | UC-11 |
| FR-TKT-09 | Manual priority override with reason | M | UC-16 |
| FR-TKT-10 | Mark ticket as duplicate of another | M | UC-17 |
| FR-TKT-11 | Auto-close resolved tickets after configurable days | S | UC-15 |
| FR-TKT-12 | Ticket watchers/CC | C | — |
| FR-TKT-13 | Custom fields, custom statuses, macros, canned responses | W | — |

## Media library (MED)

| ID | Requirement | Class | UC |
|---|---|---|---|
| FR-MED-01 | Tenant media library: upload files to folders, list/search by name/type/tag, view metadata (size, MIME, dimensions, uploader) | M | UC-50 |
| FR-MED-02 | Image thumbnails and web-sized variants generated asynchronously | M | UC-50 |
| FR-MED-03 | Reuse library items in ticket comments and as tenant branding assets; attachments uploaded on tickets appear in the library | M | UC-50 |
| FR-MED-04 | Per-tenant storage quota with usage display | S | UC-50 |
| FR-MED-05 | Trash and restore; permanent delete purges storage objects | S | UC-50 |
| FR-MED-06 | Image editing, video transcoding, CDN delivery, public galleries | W | — |

## Email channel (EML)

| ID | Requirement | Class | UC |
|---|---|---|---|
| FR-EML-01 | Outbound mail through the bundled mail server (DKIM-signed, per-tenant sender identity) or an external SMTP relay when port 25 is unavailable | M | — |
| FR-EML-02 | Inbound replies from contacts to notification emails become public comments on the right ticket (threading by plus-address and Message-ID) | S | UC-12 |
| FR-EML-03 | New inbound emails to a tenant support address create tickets with the sender as contact | S | UC-10 |
| FR-EML-04 | Quoted text and signatures stripped from inbound bodies; attachments stored in the media library | S | — |
| FR-EML-05 | Mail deliverability status (DNS records check, bounce handling) visible to tenant admins | C | — |

## Teams, agents, skills (ORG)

| ID | Requirement | Class | UC |
|---|---|---|---|
| FR-ORG-01 | CRUD teams; agents belong to zero or more teams | M | UC-03 |
| FR-ORG-02 | CRUD skills; agents hold skills | M | UC-03 |
| FR-ORG-03 | Categories require skills and may have a default team | M | UC-03 |
| FR-ORG-04 | Agent profile: capacity (max active tickets), availability status | M | UC-03 |
| FR-ORG-05 | Agent workload view (active tickets weighted by priority) | S | UC-40 |
| FR-ORG-06 | Agent shifts editable by admins/managers; agents see their own shifts | S | UC-03 |

## Automation (AUT)

| ID | Requirement | Class | UC |
|---|---|---|---|
| FR-AUT-01 | Priority score (0–100) and level P1–P4 computed deterministically from impact, urgency, customer tier and waiting time with configurable weights and thresholds (baseline strategy) | M | UC-20 |
| FR-AUT-02 | Score recomputed when its inputs change and hourly for open tickets | M | UC-20 |
| FR-AUT-03 | Explanation (factor contributions) stored and shown | M | UC-20 |
| FR-AUT-04 | Auto-assignment picks, among available agents with the category's skills and free capacity, the one with the lowest open-tickets-to-capacity ratio, ties to the least recently assigned | M | UC-21 |
| FR-AUT-05 | Assignment explanation stored and shown; manual override always possible | M | UC-14 |
| FR-AUT-06 | Duplicate detection on create returns up to five recent tickets whose word-overlap (Jaccard) score reaches the threshold, with the shared words | M | UC-22 |
| FR-AUT-07 | SLA timers (first response, resolution) started per policy, paused while pending, warning at 75 %, breach detected within one minute | M | UC-23 |
| FR-AUT-08 | On warning notify the assignee; on breach notify the assignee and managers | M | UC-23 |
| FR-AUT-09 | Tenant business calendars (time zone, weekly working hours, holidays) applied to SLA timers and ageing; policies may use 24×7 or a calendar | M | UC-04 |
| FR-AUT-11 | Agent shift schedules (weekly template + date exceptions) gate auto-assignment eligibility when enforced | S | UC-03 |
| FR-AUT-10 | Rule builder (if/then automations) | W | — |
| FR-AUT-12 | Each algorithm runs behind a replaceable strategy contract; every decision stores the strategy name and version ([ADR-0023](../adr/0023-minimal-replaceable-algorithms.md)) | M | — |

## Notifications (NOT)

| ID | Requirement | Class | UC |
|---|---|---|---|
| FR-NOT-01 | In-app notifications (bell, unread count, mark read) for assignment, mention-free comment on my ticket, SLA warning/breach | M | — |
| FR-NOT-02 | Email to agent on assignment and SLA breach; email to contact on public reply and resolution, sent through the bundled mail server or a configured SMTP relay with DKIM | M | — |
| FR-NOT-03 | Realtime update of ticket list/detail and notification bell in the SPA | S | — |
| FR-NOT-04 | Per-user notification preferences | C | — |
| FR-NOT-05 | Mobile push, SMS, chat-app channels | W | — |

## Developer platform (DEV)

| ID | Requirement | Class | UC |
|---|---|---|---|
| FR-DEV-01 | Versioned REST API under `/v1` covering tickets, comments, contacts, organisations, teams, agents, categories, tags | M | UC-06 |
| FR-DEV-02 | OAuth2 client-credentials clients per tenant | M | F6 |
| FR-DEV-03 | OpenAPI 3 document and interactive docs at `/docs/api` | M | F6 |
| FR-DEV-04 | Webhook subscriptions with HMAC-SHA256 signatures, timestamp, retries with exponential backoff, delivery log, manual retry, disable | M | F6 |
| FR-DEV-05 | Rate limiting per client/user | M | — |
| FR-DEV-06 | Idempotency-Key on ticket creation via API | S | — |
| FR-DEV-07 | Personal access tokens for users | C | — |
| FR-DEV-08 | Authorization-code OAuth for third-party apps acting on behalf of users; marketplace | W | — |

## Dashboard (ANL)

| ID | Requirement | Class | UC |
|---|---|---|---|
| FR-ANL-01 | KPI tiles: total, open, unassigned, critical, resolved today, SLA compliance %, breaches (period) | M | UC-40 |
| FR-ANL-02 | Charts: tickets by status, by priority, by category, created vs resolved over time, agent workload, SLA compliance trend | M | UC-40 |
| FR-ANL-03 | Averages: first response time, resolution time (period) | M | UC-40 |
| FR-ANL-04 | CSV/XLSX export of the filtered ticket list (shares the report export pipeline) | M | UC-41 |
| FR-ANL-05 | Dashboard built from the report catalogue (see RPT) | M | UC-40 |

## Reporting (RPT)

| ID | Requirement | Class | UC |
|---|---|---|---|
| FR-RPT-01 | Every change to reportable entities is recorded with old/new values, actor and time, including bulk changes | M | UC-42 |
| FR-RPT-02 | Report catalogue covering tickets, contacts, organisations, agents, teams, SLA, email, media, integrations and administration ([reporting.md](../04-domain/reporting.md)) with dimensions, measures, filters and period comparison | M | UC-42 |
| FR-RPT-03 | Backlog, workload and time-in-status at any past instant and over time | M | UC-42 |
| FR-RPT-04 | Drill-down from any number to the underlying records and their 360 pages | M | UC-42 |
| FR-RPT-05 | Entity 360 pages with history timeline and "as of" reconstruction for tickets, contacts, organisations, agents, teams, categories | M | UC-43 |
| FR-RPT-06 | CSV and XLSX export of any report table; print layout | M | UC-41 |
| FR-RPT-07 | Business-time durations when a calendar applies; time-zone-aware date bucketing | M | UC-42 |
| FR-RPT-08 | Read models rebuildable from history and verifiable for drift | M | — |
| FR-RPT-09 | Saved reports shared with roles | S | UC-42 |
| FR-RPT-10 | Scheduled report delivery by email | C | — |
| FR-RPT-11 | Free-form report builder, external analytics store | W | — |

## Audit (AUD)

| ID | Requirement | Class | UC |
|---|---|---|---|
| FR-AUD-01 | Security audit log of role/permission changes, user invitations/removals, API client and webhook changes, SLA/automation setting changes, tenant suspension | M | — |
| FR-AUD-02 | Audit log viewable by Tenant Admin with filters | S | — |

## Administration and settings (ADM)

| ID | Requirement | Class | UC |
|---|---|---|---|
| FR-ADM-01 | Tenant settings UI: general, branding (logo, primary colour), priority weights and thresholds, assignment weights, duplicate threshold, auto-close days | M | UC-05 |
| FR-ADM-02 | Settings validated against a schema; invalid weights rejected | M | UC-05 |
| FR-ADM-03 | User preference: theme light/dark/system, density | M | — |
