# Milestone 2 — Core product

Goal: the complete ticket workflow with all four algorithms running and explained, notifications, and the settings that configure them. Exit criteria: [00-mvp-definition.md](00-mvp-definition.md).

## Track plan

Work runs on four parallel tracks (see [12-schedule.md](12-schedule.md)): **A** backend and algorithms, **B** frontend, **C** infrastructure, mail/media plumbing, quality and academic artefacts, **D** algorithm cores and reporting. Each track can be the owner or a coding agent in its own git worktree; the owner reviews and merges. Tasks carry a `Track` line; dates are computed from [schedule.yaml](schedule.yaml) by `python3 roadmap/tools/schedule.py`, never written by hand.

| Track | Order |
|---|---|
| A | M2-03 → M2-04 → M2-05 → M2-10 → M2-12 |
| B | M2-01 → M2-02 → M2-06 → M2-07 |
| C | M2-08 → M2-09 |
| D | M2-13 → M2-11 |

Track D built the pure algorithm cores in Milestone 1; here track A wires them into requests, jobs and the database in the research order (SLA first because everything reads `due_at`, then priority, then assignment), while track D integrates duplicates and builds the reporting read models. Track B builds UI shells against MSW mocks until the matching track-A endpoints land.

---

## Epic P1 — Configuration and organisation

### `[ ]` M2-01 Tenant settings framework and branding — M
- **Do:** `Settings` service (defaults from `config/helpdesk.php` merged with `tenant_settings.data`, per-tenant cache, `settings_version` bump on write); validation schemas per section (general, branding, automation.priority, automation.assignment, automation.duplicates, tickets, sla, features); `GET/PATCH /v1/settings/{section}`; logo upload via storage intent/complete (reuses attachment flow with `branding` prefix); audit on change; UI: Settings layout with sections, General and Branding forms, live logo/primary colour preview through tokens.
- **Acceptance:** invalid weights (sum ≠ 1) → 422; changed primary colour applies without reload; settings version stored.
- **Depends:** M1-17
- **Track:** B
- **Tests:** settings validation unit tests; API tests; audit assertion.
- **Docs:** [configuration.md](../docs/03-architecture/configuration.md) keys confirmed.

### `[ ]` M2-02 Agents, teams, skills, categories — L
- **Do:** migrations (skills, teams, team_members, agent_profiles, agent_skills, category_skill, categories default_team); models; `AgentDirectory` query (eligible agents with loads); API CRUD for skills, teams (+members), categories (+required skills, default team), agent profiles (capacity, availability, skills), `GET /v1/agents/{id}/workload`; UI: Settings → Skills, Teams, Categories, Agents pages (tables + forms with Combobox pickers), availability toggle in Topbar for agents; **shifts (Should)**: `agent_shifts` weekly template + date exceptions, `shifts.enforce` tenant setting, shift check in `AgentDirectory` eligibility, Agents → Shifts weekly grid editor ([ADR-0020](../docs/adr/0020-business-calendars.md)).
- **Acceptance:** an agent can be given skills with levels and team memberships; categories require skills; availability changes reflect immediately.
- **Depends:** M1-17
- **Track:** B
- **Tests:** CRUD + isolation + permission rows; directory query test.
- **Docs:** [agents-and-teams.md](../docs/04-domain/agents-and-teams.md) confirmed.

## Epic P2 — Algorithms (backend)

### `[ ]` M2-03 SLA integration and calendars — M, critical
- **Do:** (uses `config/helpdesk.php` defaults until M2-01 exposes tenant settings) migrations `sla_policies` (with `calendar_id`), `sla_targets`, `ticket_sla_timers` (indexes on `(state, due_at)` and `(state, warning_at)`), `sla_events`, `business_calendars`, `calendar_holidays`; persistence adapter around the `SlaStrategy` binding; hooks in `CreateTicket`, `TransitionTicket` (pending, resolved, reopened, closed as duplicate), `AddComment` (first public agent reply) and priority changes; `sla:evaluate` every minute with row locks and a heartbeat; warning and breach notifications; policy selection by organisation tier; API: policies and calendars CRUD, `GET /tickets/{id}/sla`; UI: Settings → SLA policies (targets per priority, calendar), Settings → Calendars (weekly hours, holidays, time zone), SLA panel component (due, remaining time with `aria-live`, state badge).
- **Acceptance:** the SLA timelines pass end to end through the API with a frozen clock; a repeated check notifies once; 10 000 timers checked in < 5 s locally.
- **Depends:** M1-17, M1-18
- **Track:** A
- **Tests:** feature tests through the API; scheduler command test; isolation.
- **Docs:** sla-evaluation.md kept in sync with code (pseudocode ↔ methods table).

### `[ ]` M2-04 Priority integration — S, critical
- **Do:** `PriorityStrategy` binding; score on create and on impact/urgency/organisation change; `tickets:reevaluate-priority` hourly (age term); SLA recompute when the level changes; `POST /tickets/{id}/priority` manual override with reason (audit); `PriorityChanged` event; `POST /v1/settings/automation/priority/preview` (sample tickets → scores); UI: Settings → Automation → Priority (four weights, three thresholds, live preview), `PriorityExplanation` panel.
- **Acceptance:** the preview endpoint reproduces the worked-example table; the hourly command moves the ageing example from P3 to P2; override wins.
- **Depends:** M2-03, M2-02, M1-19
- **Track:** A
- **Tests:** feature tests for create, change, hourly pass and override.
- **Docs:** priority-scoring.md §7 regenerated by the preview endpoint; settings keys.

### `[ ]` M2-05 Assignment integration — S, critical
- **Do:** `AssignmentStrategy` binding; candidate loader (skills, teams, availability, shifts, open-ticket counts, last assignment); `AssignTicket` action (row lock, counters, `last_assigned_at`, `ticket_assignments` row with explanation and strategy, `TicketAssigned` event, 409 on race); auto-assign in `CreateTicket` when `automation.assignment.enabled`; `POST /tickets/{id}/assign` (manual, with the candidate list), `/auto-assign`, `/unassign`; `no_eligible_agent` notification to managers; `agents:reconcile-workload` nightly; `AssignmentExplanation` component (candidates with load, exclusions).
- **Acceptance:** the worked example assigns Chen; a concurrent assign race yields one 200 and one 409; the explanation is stored and shown.
- **Depends:** M2-02, M2-04, M1-20
- **Track:** A
- **Tests:** feature tests incl. concurrency and permissions (`tickets.assign`).
- **Docs:** agent-assignment.md worked example regenerated.

## Epic P3 — Ticket experience

### `[ ]` M2-06 Ticket UI: create, detail, transitions — XL, critical
- **Do:** ticket create page (contact Combobox with inline create, organisation display, category, impact and urgency selectors with help text, tags, attachment dropzone placeholder wired in M2-08, duplicate preview panel placeholder wired in M2-10); ticket detail page: header (number, title, status badge, priority badge with explanation popover), meta sidebar (contact, organisation tier, category, team, agent with assign dialog showing ranking, tags, timestamps), SLA panel, tabs Timeline / Comments (M2-07) / Attachments (M2-08) / Duplicates (M2-10); transition buttons per state machine with confirmation for resolve (resolution comment) and close; priority override dialog; `TransitionTicket` API wiring; ticket edit (title/description/inputs); breadcrumbs; keyboard shortcuts (a assign, r reply, e edit); optimistic status change.
- **Acceptance:** built first against MSW handlers generated from the OpenAPI contract, then verified against the real API once M2-03/M2-04/M2-05 land: golden path create → assign → in progress → pending → resolved → closed via UI; explanation panels render stored JSON; all states have loading/empty/error; axe clean.
- **Depends:** M1-17, M1-13, M1-14
- **Track:** B
- **Tests:** browser tests for create form validation and transition guards; feature tests for transition endpoint (all legal/illegal moves).
- **Docs:** screenshots list for the report; components.md statuses.

### `[ ]` M2-07 Comments: public replies and internal notes — M
- **Do:** `AddComment` action (visibility, author type, first_responded_at, last_agent/customer_reply_at, pending → in_progress on contact reply, SLA met hook, `CommentAdded` event); `GET/POST /tickets/{id}/comments`; Markdown rendering with sanitiser allow-list (backend renders to safe HTML or frontend renders with a strict renderer — choose frontend `marked` + DOMPurify equivalent; record decision); UI: comment composer with reply/note toggle (visually distinct), timeline merge of comments and events; contact email on public reply (queued mailable with tenant branding).
- **Acceptance:** first public reply marks FR met and emails the contact (Mailpit); internal notes never appear in contact emails or API responses for client tokens without `comments.internal`.
- **Depends:** M2-06
- **Track:** B
- **Tests:** feature tests (visibility, SLA hook, permission `comments.internal`), sanitiser tests.
- **Docs:** tickets.md §Comments confirmed.

### `[ ]` M2-08 Media library and attachments — XL
- **Do:** `Media` module per [media.md](../docs/04-domain/media.md): `media_items`, `media_folders`, `mediables`, tags; upload intent/complete/download endpoints per [storage.md](../docs/03-architecture/storage.md) (presigned PUT, `finfo` sniff, checksum, dimensions); quota counters and `quota_exceeded`; `GenerateImageVariants` job (intervention/image 4.3: `thumb`, `preview`); trash/restore/purge; `media:cleanup` hourly (pending > 1 h, trash > 30 days); system folders (Tickets, Email, Branding); ticket and comment attachments as `mediables`; UI: `AttachmentUploader` (multi-file, progress, retry) on ticket create and comment composer, Settings → Media library (folder tree, grid/list, search, tag, move, trash, quota bar), "pick from library" dialog.
- **Acceptance:** 25 MB limit and type allow-list enforced at intent and complete; quota enforced; thumbnails appear within seconds; library shows "used in #n"; download URL expires; cross-tenant access → 404; cleanup command removes stale items.
- **Depends:** M2-06
- **Track:** C
- **Tests:** feature tests with the `local` disk fake; variant job test; quota tests; isolation.
- **Docs:** storage.md, media.md confirmed.
### `[ ]` M2-09 Notifications — M
- **Do:** `notifications` table with `tenant_id`; notification classes from [notifications.md](../docs/04-domain/notifications.md) with `database`, `mail`, `broadcast` channels; listeners on `TicketAssigned`, `CommentAdded`, `SlaWarning`, `SlaBreached`, `PriorityChanged` (escalation), `NoEligibleAgent`; dedupe by `notification_key`; mail templates (Markdown) with tenant logo/name; `GET /notifications`, `read`, `read-all`; unread count in `/me`; broadcast events with `log` driver; UI `NotificationBell` + notifications page with polling.
- **Acceptance:** each event produces the expected in-app/email per the matrix; retried job does not duplicate; bell count updates within 30 s.
- **Depends:** M2-07, M2-03, M2-05
- **Track:** C
- **Tests:** notification tests per event (`Notification::fake`), dedupe, API.
- **Docs:** notifications.md matrix confirmed.

### `[ ]` M2-10 Duplicate integration — M, critical
- **Do:** `DuplicateStrategy` binding; candidate query (same tenant, 30 days, not closed, top 50 by trigram similarity of the title); `POST /tickets/preview-duplicates`; suggestions stored in `ticket_duplicate_suggestions` with score, shared words and strategy; `GET /tickets/{id}/duplicates`, `POST /tickets/{id}/mark-duplicate`, `POST /tickets/{id}/duplicates/{candidate}/dismiss`; UI: preview panel on create (debounced), Duplicates tab showing shared words, mark-as-duplicate dialog; Settings → Automation → Duplicates (threshold).
- **Acceptance:** the worked example suggests #1031 and ignores #1002; preview responds < 300 ms with 5 000 tickets; candidates from another tenant never appear.
- **Depends:** M1-17, M1-21
- **Track:** A
- **Tests:** feature tests; isolation.
- **Docs:** duplicate-detection.md worked example regenerated.

### `[ ]` M2-13 Reporting read models — L, critical
- **Do:** `report_ticket_intervals`, `report_ticket_facts`, `report_daily_snapshots` migrations with RLS and indexes (btree_gist); `RefreshTicketReport` job on every ticket domain event (uses `IntervalBuilder`, calendars, SLA timers); `reports:snapshot-daily` per tenant after local midnight; `reports:rebuild` and `reports:verify`; triggers attached to all reportable tables created in Milestone 2; `GET /v1/history/{type}/{id}` and `/as-of` using `ChangeReplayer`.
- **Acceptance:** rebuild equals incremental results on the demo seed; backlog-at-instant matches a brute-force count; as-of view of a contact edited three times returns each prior version.
- **Depends:** M2-03, M1-22, M1-23
- **Track:** D
- **Tests:** job and command tests; drift verification; isolation; ≈ 25 feature tests.
- **Docs:** reporting.md §Operations; history-and-time-analytics.md.

## Epic P4 — Should-have product items

### `[ ]` M2-11 Ticket list UX completion and bulk actions — M (Should)
- **Do:** full `FilterBar` (status, priority, team, agent incl. unassigned, category, tag, organisation, SLA state, created range), search, sort options, quick views (My tickets, Unassigned, Breaching soon), column visibility, bulk assign / bulk status with progress and partial-failure report (`POST /tickets/bulk`).
- **Acceptance:** every filter round-trips through the URL and the API; bulk on 50 rows completes with per-row result.
- **Depends:** M1-14, M2-06
- **Track:** D
- **Tests:** browser tests for filters; feature test for bulk partial failure.
- **Docs:** pagination-filtering.md examples confirmed.

### `[ ]` M2-12 Users and roles UI — M (Should: custom roles part)
- **Do:** Settings → Users (invite, roles, disable, resend invitation), Settings → Roles (list default roles read-only, custom role editor with permission checkboxes grouped by module).
- **Acceptance:** invited user appears pending until accepted; custom role usable on a user; last-owner guard surfaced.
- **Depends:** M1-09
- **Track:** A
- **Tests:** browser test for invite form; API covered in M1-09.
- **Docs:** security.md.

## Milestone 2 exit review

Run exit criteria; regenerate worked-example tables from the preview endpoints and paste into the algorithm docs; update statuses; risk review; decide Reverb (M3-16) go/no-go based on buffer consumed.
