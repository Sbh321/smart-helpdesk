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

| ID | Report | Dimensions | Measures | Source |
|---|---|---|---|---|
| RPT-T01 | Ticket volume | date, channel, category, priority, organisation, team | created, resolved, closed, reopened, net change | facts |
| RPT-T02 | Backlog over time and on a date | date, status, priority, team, agent, age bucket | open tickets at end of day or at an instant | intervals, snapshots |
| RPT-T03 | Time in status | status, priority, team, agent, category | average, median, p90 wall-clock and business time per status | intervals |
| RPT-T04 | Status flow | from status → to status | transition counts and rates, rework loops (resolved → in progress) | ticket_events |
| RPT-T05 | Ageing | age bucket (< 1 d, 1–3 d, 3–7 d, 7–30 d, > 30 d), priority, team | open tickets, oldest ticket | intervals |
| RPT-T06 | Response and resolution times | date, priority, team, agent, category, tier | first response and resolution: average, median, p90 (business and wall-clock) | facts |
| RPT-T07 | Reopens and rework | category, agent, team | reopen rate, tickets reopened more than once, time to reopen | facts, events |
| RPT-T08 | Assignment behaviour | date, team, agent, reason (auto, manual, reassign), strategy | assignments, reassignments per ticket, unassigned time, no-eligible-agent events | ticket_assignments, facts |
| RPT-T09 | Priority behaviour | priority, category, tier, strategy | score distribution, manual overrides, level changes from ageing | facts, events |
| RPT-T10 | Duplicates | date, category | suggestions shown, accepted, dismissed, acceptance rate (production precision), tickets closed as duplicate | ticket_duplicate_suggestions |
| RPT-T11 | Workload heatmap | weekday × hour (tenant time zone) | tickets created, replies sent | facts, comments |
| RPT-T12 | Ticket lifecycle trace | one ticket | ordered intervals with durations, SLA timers, assignments, emails | intervals, events |

### Contacts and organisations

| ID | Report | Dimensions | Measures |
|---|---|---|---|
| RPT-C01 | Contact growth | date, organisation, created via (ui, api, email) | new contacts, active contacts (raised a ticket in the period) |
| RPT-C02 | Top requesters | contact, organisation | tickets, open tickets, reopen rate, breaches experienced |
| RPT-C03 | Customer experience | organisation, tier | first response and resolution times, SLA compliance, open backlog |
| RPT-C04 | Organisation changes | organisation | tier changes over time, contacts added/removed (from change capture) |
| RPT-C05 | Contact 360 | one contact | profile as of any date, change log, all tickets with outcomes, emails received/sent, response times experienced |
| RPT-C06 | Organisation 360 | one organisation | tier history, contacts, ticket trend, SLA compliance, top categories |

### Agents and teams

| ID | Report | Dimensions | Measures |
|---|---|---|---|
| RPT-A01 | Agent workload over time | date, agent, team | open assigned tickets at end of day, weighted load, capacity utilisation |
| RPT-A02 | Agent performance | agent, period | assigned, resolved, first replies, median handling and resolution time, SLA compliance, reopen rate |
| RPT-A03 | Fairness | date, team | Jain's index and coefficient of variation of weighted load (same metric as the assignment experiment) |
| RPT-A04 | Availability and shifts | agent, date | scheduled shift hours, hours marked available, auto-assignments received outside shifts (should be 0) |
| RPT-A05 | Team comparison | team | volume, backlog, response/resolution times, compliance |
| RPT-A06 | Skill coverage | skill, category | agents holding each skill by level, tickets needing it, no-eligible-agent events |
| RPT-A07 | Agent / team 360 | one agent or team | profile and skill history, membership history, workload trend, current queue |

### SLA

| ID | Report | Dimensions | Measures |
|---|---|---|---|
| RPT-S01 | SLA compliance | date, policy, calendar, priority, team, tier, timer kind | met, breached, compliance % |
| RPT-S02 | Breach analysis | cause (no first response, late resolution), team, category | breaches, average lateness |
| RPT-S03 | Pause behaviour | policy, category | paused time share, tickets pending more than N hours |
| RPT-S04 | At-risk list | now | running timers in warning with remaining time |

### Channels, media, integrations, administration

| ID | Report | Measures |
|---|---|---|
| RPT-E01 | Email channel | inbound by outcome (comment, ticket, ignored, unrouted, rejected), outbound volume, tickets created by email |
| RPT-M01 | Media usage | storage used over time, by type and folder, top uploaders, quota headroom |
| RPT-I01 | Webhook reliability | deliveries, success rate, median latency, retries, dead deliveries per subscription |
| RPT-I02 | API client activity | tickets and contacts created per client, token issues, last use |
| RPT-G01 | Administrative activity | audit actions over time by actor and action; role and permission changes |
| RPT-G02 | Configuration history | changes to SLA policies, calendars, automation weights and thresholds, and algorithm strategy versions, with before/after values |

## Report page behaviour

- URL holds every parameter (`/acme/reports/rpt-t06?period=last_30d&group=team&compare=previous`), so reports are shareable within the workspace.
- Each report shows KPI tiles, one chart (bar, line, stacked area, heatmap, histogram or table as declared), a data table with the same numbers, and "view records" drill-down.
- Period comparison shows the change against the previous period.
- Time zone: the tenant default calendar's zone for date bucketing; durations in business time when the relevant policy has a calendar, otherwise wall-clock (always labelled).
- Every chart has an accessible table alternative ([accessibility](../06-design-system/accessibility.md)).
- Exports: CSV and XLSX of the table (queued, delivered through the media library); "print" layout for PDF.
- Saved reports (Should): name, parameters, owner, shared with roles. Scheduled delivery by email (Could).

## Entity 360 pages

| Entity | Sections |
|---|---|
| Ticket | header and explanations; lifecycle trace (RPT-T12); SLA timers; comments and emails; attachments; change log; as-of view |
| Contact | profile; organisation; tickets with outcomes; experience metrics; emails; change log; as-of view |
| Organisation | profile and tier history; contacts; ticket trend; SLA compliance; change log; as-of view |
| Agent | profile, skills, teams, shifts; workload trend; performance; current queue; change log; as-of view |
| Team | members over time; backlog and performance; change log |
| Category | required skills history; volume and times; top agents |

## API

`GET /v1/reports` (catalogue filtered by permission), `GET /v1/reports/{id}` (definition), `POST /v1/reports/{id}/run` (parameters → rows, totals, comparison), `GET /v1/reports/{id}/records` (drill-down, paginated), `POST /v1/reports/{id}/exports` (CSV/XLSX job), `GET /v1/history/{entity_type}/{id}` (change log, cursor), `GET /v1/history/{entity_type}/{id}/as-of?at=` (reconstructed attributes), `GET /v1/{entity}/{id}/overview` (360 metrics), `GET/POST/PATCH/DELETE /v1/saved-reports`.

## Operations

`reports:refresh-ticket` (queued per ticket on each domain event), `reports:snapshot-daily` (per tenant after local midnight), `reports:rebuild {--tenant} {--from}` (recompute intervals, facts and snapshots from history), `reports:verify` (compares derived tables with a fresh computation on a sample and reports drift).
