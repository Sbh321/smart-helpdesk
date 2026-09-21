# Reporting domain

Decision: [ADR-0022](../adr/0022-reporting-and-history.md). Algorithms: [history-and-time-analytics.md](../05-algorithms/history-and-time-analytics.md). The management dashboard ([FR-ANL](../02-product/functional-requirements.md)) is a fixed selection of these reports.

## Building blocks

| Concept | Meaning |
|---|---|
| **Report** | A catalogued, parameterised analysis (`ReportDefinition`) with allowed dimensions, measures, filters, a default chart and a drill-down target |
| **Dimension** | A grouping axis: date (day/week/month/quarter), weekday, hour, team, agent, category, priority, status, organisation, customer tier, channel (`ui`, `api`, `email`), tag, SLA policy, calendar, contact |
| **Measure** | A number: counts, sums, averages, medians, 90th percentiles, rates, fairness index |
| **Filter** | Period (with comparison to the previous period), any dimension value, SLA state, created-via, reopened, duplicate |
| **Drill-down** | From any number to the list of records behind it, then to the record's 360 page |
| **Entity 360** | A record page with current state, key metrics, related records, full history timeline and an "as of" view |
| **As-of view** | The attributes of a record reconstructed at a chosen date and time |
| **Snapshot** | End-of-day state per tenant and dimension used for long-range trends |

## Report catalogue (MVP)

### Tickets

| ID | Report | Dimensions | Measures | Source | Status |
|---|---|---|---|---|---|
| RPT-T01 | Ticket volume | date, channel, category, priority, organisation, team | created, resolved, closed, reopened, net change | facts | Built (M3-02) |
| RPT-T02 | Backlog over time and on a date | date, status, priority, team, agent, age bucket | open tickets at end of day or at an instant | intervals, snapshots | Built (M3-02) |
| RPT-T03 | Time in status | status, priority, team, agent, category | average, median, p90 wall-clock and business time per status | intervals | Built (M3-02) |
| RPT-T04 | Status flow | from status → to status | transition counts and rates, rework loops (resolved → in progress) | ticket_events | Built (M3-02) |
| RPT-T05 | Ageing | age bucket (< 1 d, 1–3 d, 3–7 d, 7–30 d, > 30 d), priority, team | open tickets, oldest ticket | intervals | Built (M3-02) |
| RPT-T06 | Response and resolution times | date, priority, team, agent, category, tier | first response and resolution: average, median, p90 (business and wall-clock) | facts | Built (M3-02) |
| RPT-T07 | Reopens and rework | category, agent, team | reopen rate, tickets reopened more than once, time to reopen | facts, events | Built (M3-02) |
| RPT-T08 | Assignment behaviour | date, team, agent, reason (auto, manual, reassign), strategy | assignments, reassignments per ticket, unassigned time, no-eligible-agent events | ticket_assignments, facts | Built (M3-02) |
| RPT-T09 | Priority behaviour | priority, category, tier, strategy | score distribution, manual overrides, level changes from ageing | facts, events | Built (M3-02) |
| RPT-T10 | Duplicates | date, category | suggestions shown, accepted, dismissed, acceptance rate (production precision), tickets closed as duplicate | ticket_duplicate_suggestions | Built (M3-02) |
| RPT-T11 | Workload heatmap | weekday × hour (tenant time zone) | tickets created, replies sent | facts, comments | Built (M3-02) |
| RPT-T12 | Ticket lifecycle trace | one ticket | ordered intervals with durations, SLA timers, assignments, emails | intervals, events | Entity 360 page |

### Contacts and organisations

| ID | Report | Dimensions | Measures | Status |
|---|---|---|---|---|
| RPT-C01 | Contact growth | date, organisation, created via (ui, api, email) | new contacts, active contacts (raised a ticket in the period) | Built (M3-02) |
| RPT-C02 | Top requesters | contact, organisation | tickets, open tickets, reopen rate, breaches experienced | Built (M3-02) |
| RPT-C03 | Customer experience | organisation, tier | first response and resolution times, SLA compliance, open backlog | Built (M3-02) |
| RPT-C04 | Organisation changes | organisation | tier changes over time, contacts added/removed (from change capture) | Built (M3-02) |
| RPT-C05 | Contact 360 | one contact | profile as of any date, change log, all tickets with outcomes, emails received/sent, response times experienced | Entity 360 page |
| RPT-C06 | Organisation 360 | one organisation | tier history, contacts, ticket trend, SLA compliance, top categories | Entity 360 page |

### Agents and teams

| ID | Report | Dimensions | Measures | Status |
|---|---|---|---|---|
| RPT-A01 | Agent workload over time | date, agent, team | open assigned tickets at end of day, weighted load, capacity utilisation | Built (M3-02) |
| RPT-A02 | Agent performance | agent, period | assigned, resolved, first replies, median handling and resolution time, SLA compliance, reopen rate | Built (M3-02) |
| RPT-A03 | Fairness | date, team | Jain's index and coefficient of variation of weighted load (same metric as the assignment experiment) | Built (M3-02) |
| RPT-A04 | Availability and shifts | agent, date | scheduled shift hours, hours marked available, auto-assignments received outside shifts (should be 0) | Built (M3-02) |
| RPT-A05 | Team comparison | team | volume, backlog, response/resolution times, compliance | Built (M3-02) |
| RPT-A06 | Skill coverage | skill, category | agents holding each skill by level, tickets needing it, no-eligible-agent events | Built (M3-02) |
| RPT-A07 | Agent / team 360 | one agent or team | profile and skill history, membership history, workload trend, current queue | Entity 360 page |

### SLA

| ID | Report | Dimensions | Measures | Status |
|---|---|---|---|---|
| RPT-S01 | SLA compliance | date, policy, calendar, priority, team, tier, timer kind | met, breached, compliance % | Built (M3-02) |
| RPT-S02 | Breach analysis | cause (no first response, late resolution), team, category | breaches, average lateness | Built (M3-02) |
| RPT-S03 | Pause behaviour | policy, category | paused time share, tickets pending more than N hours | Built (M3-02) |
| RPT-S04 | At-risk list | now | running timers in warning with remaining time | Built (M3-02) |

### Channels, media, integrations, administration

| ID | Report | Measures | Status |
|---|---|---|---|
| RPT-E01 | Email channel | inbound by outcome (comment, ticket, ignored, unrouted, rejected), outbound volume, tickets created by email | Arrives with M3-19 (inbound mail) |
| RPT-M01 | Media usage | storage used over time, by type and folder, top uploaders, quota headroom | Built (M3-02) |
| RPT-I01 | Webhook reliability | deliveries, success rate, median latency, retries, dead deliveries per subscription | Arrives with M3-05 (webhooks) |
| RPT-I02 | API client activity | tickets and contacts created per client, token issues, last use | Arrives with M3-04 (API clients) |
| RPT-G01 | Administrative activity | audit actions over time by actor and action; role and permission changes | Built (M3-02) |
| RPT-G02 | Configuration history | changes to SLA policies, calendars, automation weights and thresholds, and algorithm strategy versions, with before/after values | Built (M3-02) |

## Report page behaviour

- URL holds every parameter (`/acme/reports/rpt-t06?period=last_7d&group=team&compare=previous&priority=P1,P2`), so reports are shareable within the workspace. As built (M3-20): `period` (default `last_30d`, left out of the URL), `from`+`to` (a custom range, which wins over `period`), `compare=previous`, `group` (left out for the report's default dimension), `chart` (the measure the chart shows), one flat key per report filter with a comma list (`none` for empty, as on the API), and `drill` / `drill_page` for an open records list. Unknown keys are ignored; a parameter change closes the records list.
- Each report shows KPI tiles (one per measure: the period's total, and with the comparison on the change against the previous period, or "too few records" when the API suppressed it), one chart (as declared, see below), a data table with the same numbers (the shared `DataTable`, paged and sorted in the browser), and drill-down: every non-zero count in the table, and "View the records of the whole period", opens the records (50 a page) with links to the ticket, contact or organisation page.
- Chart per declared kind: `line` and `stacked_area` are drawn over time only; grouped by anything else they become horizontal bars. `stacked_area` draws overlapping areas, because a run has one dimension and its measures (end, average and highest backlog) are not parts of a whole. `heatmap` needs `weekday_hour` (a 7 × 24 grid) and falls back to bars. `histogram` is gapless upright bars; `table` has no chart. The chart shows the first measure and the others of the same unit (one value axis), or the one measure chosen in "Chart shows".
- Period comparison shows the change against the previous period. Ageing (T05) and at-risk (S04) describe now: the page hides the period and comparison controls for them (MVP-SHORTCUT: the keys are listed in the SPA; V1: a `period_applies` flag in the definition).
- Time zone: the tenant default calendar's zone for date bucketing; durations in business time when the relevant policy has a calendar, otherwise wall-clock (always labelled). The page says which zone days are counted in.
- Every chart has an accessible table alternative ([accessibility](../06-design-system/accessibility.md)); on the report page it is the data table, on the dashboard each chart's own table (visually hidden, toggleable).
- Exports: "Export CSV" and "Export XLSX" queue a file of the table with the page's current parameters (see [As built (M3-09)](#as-built-m3-09)); the ticket list has the same two buttons for its current filters, search and sort. Shown with `reports.export`. "Print" uses the print stylesheet: navigation, parameter bar and buttons hidden, tables unclipped.
- Saved reports (Should): not built. Scheduled delivery by email (Could).

## Dashboard (M3-01)

`GET /v1/dashboard?period=` (`reports.view`; the preset periods only, default `last_30d`) is `Reporting\Dashboard\Dashboard`: a fixed selection of catalogue reports run through `ReportRunner` (so every number equals the same report run with the same parameters and shares its five-minute cache), with no SQL of its own. Tiles (`Dashboard::KPIS`), one run per report with its default group and `compare`: tickets created and resolved (T01), open now (T05, never compared), median first response and resolution (T06), SLA compliance and breaches (S01), reopen rate (T07). Series (`Dashboard::SERIES`): created and resolved per day (T01), backlog per day (T02), response and resolution by priority (T06), SLA compliance per week (S01), open assigned tickets per agent (A01), median time in status (T03). Each tile names its `report` and `measure`, each series its `report`, `parameters` (`period`, `group`, `measures`), measures with units, rows and `truncated`, so the SPA links to the full report. A tile or series whose report the caller may not run is left out (without `agents.view` there is no workload series). FR-ANL-01's "unassigned", "critical" and "resolved today" tiles and FR-ANL-02's by-status/by-category charts are one click away in the reports (T05 by priority, T01 by category) rather than on the dashboard. Tests: `tests/Feature/Reporting/DashboardApiTest.php` (every tile and series equals the report run, period, 422, permissions).

## Entity 360 pages

| Entity | Sections |
|---|---|
| Ticket | header and explanations; lifecycle trace (RPT-T12); SLA timers; comments and emails; attachments; change log; as-of view |
| Contact | profile; organisation; tickets with outcomes; experience metrics; emails; change log; as-of view |
| Organisation | profile and tier history; contacts; ticket trend; SLA compliance; change log; as-of view |
| Agent | profile, skills, teams, shifts; workload trend; performance; current queue; change log; as-of view |
| Team | members over time; backlog and performance; change log |
| Category | required skills history; volume and times; top agents |

### As built (M3-21)

Confirmed against the table above, with these differences. Every page has **Overview** (the
`/overview` endpoint: key figures, related records, trends) and **History** (change log and as-of view)
tabs; contacts, organisations and tickets keep their own details as the first tab, agents, teams and
categories get a read-only page linked from Settings and from report drill-downs. Ticket: key figures,
lifecycle trace (RPT-T12, the intervals of `report_ticket_intervals`, the open one running to now) and
SLA timers; comments, attachments and the domain-event timeline stay on their own tabs, and History merges
field changes with the domain events. Contact: figures over all its tickets, organisation, recent tickets,
tickets per week; emails wait for M3-19. Organisation: tier and contacts, top categories, tier history,
tickets per week. Agent: capacity, availability, open tickets, 30-day performance, skills, teams, backlog
per day (shifts stay on Settings → Shifts). Team: 30-day performance, members with join date, backlog per
day ("members over time" beyond the join date is not shown). Category: ticket figures, required skills,
top Agents, tickets per week ("required skills history" is visible in its History tab). The as-of view
shows each earlier state (for example a contact's organisation before each move), the differences from
now and "did not exist" before creation. History tabs follow [security.md](../03-architecture/security.md)
§History as built; audit entries join the timeline with M3-03. `EntityOverviewResource` and
`AsOfResource` now declare the shapes of `metrics`, `related`, `trends`, `attributes` and `differences`
for the OpenAPI document (they were typed as strings).

## API

`GET /v1/reports` (catalogue filtered by permission), `GET /v1/reports/{id}` (definition), `POST /v1/reports/{id}/run` (parameters → rows, totals, comparison), `GET /v1/reports/{id}/records` (drill-down, paginated), `POST /v1/reports/{id}/exports` (CSV/XLSX job), `GET /v1/history/{entity_type}/{id}` (change log, cursor), `GET /v1/history/{entity_type}/{id}/as-of?at=` (reconstructed attributes), `GET /v1/{entity}/{id}/overview` (360 metrics), `GET/POST/PATCH/DELETE /v1/saved-reports`.

## Operations

`reports:refresh-ticket` (queued per ticket on each domain event), `reports:snapshot-daily` (per tenant after local midnight), `reports:rebuild {--tenant} {--from}` (recompute intervals, facts and snapshots from history), `reports:verify` (compares derived tables with a fresh computation on a sample and reports drift).

### As built (M2-13)

- **Read models.** `report_ticket_intervals`, `report_ticket_facts` and `report_daily_snapshots` (Reporting module migration `…_create_report_read_models`, `btree_gist` for the span index). Facts and snapshots have a surrogate UUID `id` like every other table; their natural keys `(tenant_id, ticket_id)` and `(tenant_id, day, dimension, dimension_key)` are unique indexes led by `tenant_id` (the isolation schema test requires it). Agent and team ids in the read models have no foreign key, so history survives a deleted agent. The tables are tenant-scoped and registered in `TenantTables` for the M3-07 RLS policies; they carry no change-capture trigger because they are derived.
- **Refresh.** `RefreshTicketReport` (queue `reports`, unique per ticket until it starts, three tries) runs after commit on every ticket domain event: created, updated, status changed, assigned, priority changed, comment added, lifecycle, first public reply, SLA warning and breach. `TicketReportWriter` recomputes the ticket's intervals and facts from `ticket_events` (whose times come from the application `Clock`, like the ticket's own) and replaces its rows, so the result is independent of how often or in which order it runs.
- **Intervals.** Tracked state: status, assignee, team and effective priority. A tracked attribute starts with the old value of its first change (or the `created` event's value, or the current value when nothing changed it), so seeded tickets without a `created` event still work. An event dated before the ticket (a shifted demo clock, an import) counts as happening at creation. Business time uses the calendar of the ticket's latest resolution timer, else wall-clock. The open interval stores no durations.
- **Facts.** As `entities.md` lists. `pending_s` and `unassigned_s` count closed intervals only, so they do not depend on when the refresh ran; `email_in_count` counts requester replies (the MVP has no inbound mail yet); SLA outcomes are those of the latest cycle (`running` covers running, warning and paused); strategies are stored as `name@version`.
- **Snapshots.** `reports:snapshot-daily` (hourly at :20, one server) writes each workspace's local yesterday, replacing the day's rows. Dimensions `none, team, agent, priority, category, status`; metrics `backlog` (not resolved or closed at the end of the day), `created`, `resolved`, `reopened`, `breached`, and `load` for agents (backlog / current capacity). A flow counts under the value the ticket had at that instant; `category` uses the current category.
- **Commands.** `reports:rebuild {--tenant} {--from}` recomputes every ticket and every day from the first ticket's local day to yesterday (5 130 tickets in 21 s on the dev stack). `reports:verify {--tenant} {--sample=50} {--days=5}` recomputes a sample and fails on any difference.
- **History API.** `GET /v1/history/{type}/{id}` (newest change first, cursor pages of 50) and `GET /v1/history/{type}/{id}/as-of?at=` (`exists`, `attributes`, `differences` to now, `versions_after`). `{type}` is the recorded table; the current row is read with `to_jsonb` so it has the trigger's formats. Columns capture does not record are never shown. Access: [security.md](../03-architecture/security.md) §History.
- **Comment bodies** are no longer recorded (ADR-0022 records comment metadata only); a migration removed the bodies captured since M2-07.
- **Not built here.** The report catalogue, runner, report pages, exports and entity 360 pages (M3-01, M3-02, M3-03).

### As built (M3-02)

- **Catalogue.** `App\Modules\Reporting\Reports\ReportCatalogue::REPORTS` lists 28 reports in catalogue order, one class each in `Reports/Catalogue/` (`rpt-t01` `TicketVolume`, `rpt-t02` `TicketBacklog`, `rpt-t03` `TimeInStatus`, `rpt-t04` `StatusFlow`, `rpt-t05` `Ageing`, `rpt-t06` `ResponseAndResolution`, `rpt-t07` `ReopensAndRework`, `rpt-t08` `AssignmentBehaviour`, `rpt-t09` `PriorityBehaviour`, `rpt-t10` `Duplicates`, `rpt-t11` `WorkloadHeatmap`, `rpt-c01` `ContactGrowth`, `rpt-c02` `TopRequesters`, `rpt-c03` `CustomerExperience`, `rpt-c04` `OrganisationChanges`, `rpt-a01` `AgentWorkload`, `rpt-a02` `AgentPerformance`, `rpt-a03` `Fairness`, `rpt-a04` `AvailabilityAndShifts`, `rpt-a05` `TeamComparison`, `rpt-a06` `SkillCoverage`, `rpt-s01` `SlaCompliance`, `rpt-s02` `BreachAnalysis`, `rpt-s03` `PauseBehaviour`, `rpt-s04` `AtRisk`, `rpt-m01` `MediaUsage`, `rpt-g01` `AdministrativeActivity`, `rpt-g02` `ConfigurationHistory`). Every one except `rpt-t02` is an `SqlReport`: one grouped query, the totals and the previous period's totals.
- **`SqlReport` hooks added.** `sourceFor(parameters)` (a source that depends on the dimension: agent or team snapshots, fairness per day or per team), `sourceBindings(parameters, from, to)` (`?` placeholders inside the source: the tenant id for sources that filter their own tables, "now", the period for generated days), `periodApplies()` (false for the "now" reports: no period clause, no comparison), `orderBy()` (ranked reports), `now()`/`until(to)` (the `Clock`; open durations stop at the end of the period or now, whichever is first). `{tz}` is now replaced in the whole statement, not only in the dimension.
- **Permissions** (beyond `reports.view`): ticket and SLA reports `tickets.view`; contact reports `contacts.view` (plus `tickets.view` when they count tickets; `rpt-c04` needs only `contacts.view`); agent reports `agents.view` (plus `tickets.view`, except `rpt-a03`); `rpt-m01` `media.view`; `rpt-g01` `audit.view`; `rpt-g02` `settings.manage`. Agents still see every agent's numbers ("own performance only" in [security.md](../03-architecture/security.md) is not enforced yet).
- **Cohorts.** Fact-based reports (T06, T07, T09, C02, C03, A02, A05) cover the tickets created in the period, grouped by their current values. Event-based ones are dated by the event: T04 (status changes), T08 (assignment records), T10 (suggestions), T11 (creation and public agent replies), S02 (breach time), C04 and G02 (change capture), G01 (audit). T03 covers the status intervals that started in the period; an open interval counts wall-clock time to the end of the period or now, and has no business time yet (business measures cover closed intervals only). S01 and S03 cover the timers started in the period; a timer that breached counts as breached even if it was met later.
- **Ageing (T05) and at-risk (S04) ignore the period.** Both describe now (the `Clock`): T05 reads the open tickets from `tickets` (age since creation, buckets keyed `1`–`5` and labelled < 1 d … > 30 d); S04 lists running timers past their warning time and before their due time, soonest due first, with the wall-clock time remaining. Their comparison is always null.
- **Fairness (A03)** uses the formulas of `FairnessIndex` (Jain's (Σx)² ÷ (n·Σx²) and σ ÷ μ) in SQL, because it runs over snapshot rows; a test checks one day against `FairnessIndex::jain` and `coefficientOfVariation`. x is each current agent's end-of-day `load` from the snapshots, 0 when the agent has no snapshot row that day; an all-zero day is perfectly fair (1 and 0), as in `FairnessIndex`. A row is the average of its days; grouped by team, the agents are the team's current members.
- **A01** reads the agent (or team) snapshots without the unassigned `-` row: by day the sum over agents, by agent or team the daily average; utilisation is the average `load` (agents only).
- **A04** computes scheduled shift hours per agent and local day from the **current** shift plan (a date exception replaces the day's weekly rows; a day off is 0) and counts automatic assignments received outside those shifts, only for agents that have a shift plan. Shift history (change capture on `agent_shifts`) and "hours marked available" (availability history) are not replayed yet.
- **C01** has no "created via" dimension: contacts do not record their channel yet. **C04** reads `entity_changes`: tier changes are updates of `organizations.tier`; a contact is added when it is created in or moved into an organisation and removed when it moves out or is deleted. Changes made before change capture was attached (M2) are not seen.
- **Not built from the catalogue rows.** T07 "time to reopen", T08 "unassigned time" (in the facts as `unassigned_s`, not yet exposed here), M01 "quota headroom" and "storage over time" (M01 counts completed uploads of the period, trash included; unit `bytes`, a new measure unit), A06 by category, S03 per calendar.
- **Deferred reports.** E01 email channel arrives with M3-19, I01 webhook reliability with M3-05, I02 API client activity with M3-04: their tables do not exist yet.
- **Tests.** `tests/Feature/Reporting/Catalogue*Test.php`: every report runs with its defaults in under a second on 40 seeded tickets and with every dimension; each report's key total is checked against an independent SQL or PHP computation; permission and isolation cases per group. The fixture is `catalogueWorkspace()` in `CatalogueHelpers.php`.
- **Entity overviews.** `GET /v1/{tickets|contacts|organizations|agents|teams|categories}/{id}/overview` (`Overviews\EntityOverviews`, `EntityOverviewResource`): `{entity, id, title, metrics, related, trends}`, each needing the entity's view permission (`tickets.view`, `contacts.view`, `agents.view`), 404 across workspaces. Ticket: lifecycle trace from the intervals (the open one runs to now) and SLA timers. Contact and organisation: tickets, open, reopen rate, breaches, SLA compliance, median first response and resolution, recent tickets, tier history from `entity_changes` (creation counts as the first tier), top categories, 12-week trend. Agent and team: skills, teams or members, current queue, 30-day performance from the facts, 30-day backlog from the snapshots. Category: required skills, the same ticket metrics, top agents. Drill-down rows share one shape: `{id, entity, label, subtitle, status}`.

### As built (M3-09)

- **Table.** `report_exports` (Reporting module migration `…_create_report_exports_table`): `report_key` (a catalogue id or `tickets-list`), `parameters` jsonb, `format` (`csv`, `xlsx`), `state` (`queued, running, ready, failed`), `requested_by_user_id` (FK users, cascade), `media_item_id` (FK media_items, set null), `row_count`, `error` (`too_large`, `quota_exceeded`, `forbidden`, `failed`), `started_at`, `finished_at`. Tenant-scoped (`TenantTables`, immutable `tenant_id`, index `(tenant_id, requested_by_user_id, created_at)`); not reportable: an export is a delivery, like a notification.
- **Requests.** `POST /v1/reports/{report}/exports` validates the parameters exactly as a run does (`ReportRunner::parameters`, errors keyed `parameters.*`) and stores them with the period fixed as local `from`/`to` dates plus `period_name`, so a `last_7d` export queued before midnight covers the week that was asked for. `POST /v1/exports/tickets` reuses `IndexTicketsRequest`'s rules (`ExportTicketsRequest` extends it) and stores the list criteria (`filter`, `search`, `sort`, `explicit_sort`); `me` in the assignee filter is resolved against the requester when the job runs. `TicketListQuery` now takes a `TicketListCriteria` value (built by `IndexTicketsRequest::criteria()` or from the stored parameters) and exposes `query()` besides `paginate()`. Both answer 202 with the export; both are throttled to 10 a minute per user (`throttle:exports`).
- **Cap.** A ticket-list export is refused with 422 on `filter` above 50 000 tickets (`ExportTables::TICKET_ROW_CAP`, counted when requested); the job also stops at 50 000 rows. A report export is the run's rows (the runner's own row cap applies) plus a `Total` row.
- **Job.** `Jobs\ExportReport` on the `reports` queue (`supervisor-1`; job timeout 80 s, below the redis `retry_after` of 90 s; two tries): checks the requester again (active, `reports.export`, and the report's permissions or `tickets.view`, else `failed`/`forbidden`), runs the report through `ReportRunner` (so the file equals the page and shares its five-minute cache) or the list through `TicketListQuery` in chunks of 1 000 (`lazy()`, the list order ends with `id`), writes a temporary file with `Exports\ExportFileWriter`, stores it and fires `Events\ReportExportReady`. A ready or failed export is never written again; `failed()` marks the export `failed`/`failed` after the last try. On the dev stack the 5 118 active tickets export in about two seconds (918 KB CSV).
- **Files.** CSV: UTF-8 with a byte-order mark, `fputcsv` with RFC 4180 quoting and no escape character; a text value starting with `=`, `+`, `-`, `@`, tab or CR gets a leading apostrophe (CSV injection). XLSX: openspout 5.11 (`openspout/openspout ^5.11`, [versions](../01-research/versions.md)), one sheet named after the report, bold header, strings always string cells (openspout would turn a leading `=` into a formula), numbers numeric. Report headers are the dimension label and the measure labels with the unit (`(seconds)`, `(%)`, `(bytes)`); values are the raw numbers of the API (durations in seconds). Ticket columns: Number, Title, Status, Priority (effective), Impact, Urgency, Category, Team, Assignee, Contact, Contact email, Organisation, Created, Updated, First response, Resolved, SLA state, SLA due; times `YYYY-MM-DD HH:MM` in the workspace time zone. `row_count` counts data rows, not the header or `Total`. File name `{report title or "Tickets"} {YYYY-MM-DD HHMM}.{csv|xlsx}` in the workspace zone.
- **Storage.** `Media\Actions\StoreGeneratedFile` stores the file as a `ready` Media item with `source = system` in the system `Reports` folder ([media.md](media.md)), under the same quota rules as an upload: a `pending` item reserves the bytes under the tenant lock, the object is streamed to `media/{id}/original.{ext}` (`MediaStorage::putStream`), and the item turns `ready` with the counter update last. Over the quota → `failed`/`quota_exceeded`; over the 25 MiB file limit → `failed`/`too_large`.
- **Access.** `GET /v1/exports/{export}` shows the requester's own exports only; anyone else gets 404. The file downloads through `GET /v1/media/{id}/download` (`media.view`), which for a `system` item answers 404 to anyone but its requester, so a workspace owner does not read a manager's export either.
- **Notification.** "Export ready" (in-app and broadcast, not mailed, as the [notifications matrix](notifications.md) says) to the requester, with the file's Media id and name.
- **SPA.** `ExportControls` (report page header and ticket list): request → toast "Export queued" → poll `GET /v1/exports/{id}` every 2 s until ready or failed → a download link with the file name and row count (and a toast); a refused request shows the field error (the 50 000 cap) or the problem detail; a failed export names its reason. The bell and the notifications page show "Your export is ready" with a link to the file.
- **Not built.** A list of my exports, retention (storage.md's 7-day lifecycle rule applied to exports: they are Media items now and stay until trashed), scheduled delivery.
- **Tests.** `tests/Feature/Reporting/ExportTest.php` (CSV and XLSX for a report and for the ticket list, headers, rows and totals against the run, list order, formula-safe text, notification, requester-only access across users and workspaces, permissions, validation, quota, re-check in the job); rows in `RoleMatrixTest` and `RouteProtectionTest`; SPA `src/features/reports/exports.browser.test.tsx`.
