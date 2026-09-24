# SLA domain

## Policies

```mermaid
erDiagram
    SLA_POLICY ||--o{ SLA_TARGET : has
    SLA_POLICY {
        uuid id
        uuid tenant_id
        string name
        bool is_default
        enum applies_to_tier "null | standard | premium | enterprise"
        numeric warning_fraction "0.75"
        uuid calendar_id "null = 24x7"
    }
    SLA_TARGET {
        uuid policy_id
        enum priority_level "P1..P4"
        int first_response_minutes
        int resolution_minutes
    }
```

Policy selection at ticket creation: the policy whose `applies_to_tier` equals the ticket organisation's tier, else the default policy. Selection is re-run only if the ticket's organisation changes.

Default targets seeded for new tenants (calendar hours, 24×7):

| Priority | First response | Resolution |
|---|---|---|
| P1 | 30 min | 4 h |
| P2 | 1 h | 8 h |
| P3 | 4 h | 24 h |
| P4 | 8 h | 72 h |

As built in M2-03 so far: tenant provisioning seeds these targets idempotently for newly provisioned tenants; the schema migration backfills the same policy and targets for existing tenants. Ticket creation starts both timers in the same transaction and selects an Organisation-tier policy before falling back to the default. Entering pending pauses the timers; leaving pending resumes them with calendar elapsed time. Resolution meets the resolution timer, and reopening starts its next cycle. The queued evaluator records warning/breach events and ticket history. First-public-reply and priority-change hooks, policy and Business calendar/holiday management routes, ticket timer read route, and initial Settings UI are implemented, pending the Week 2 verification pass. Notification delivery remains with M2-09.

## Timers

Each ticket gets two timer rows in `ticket_sla_timers`:

| Field | Notes |
|---|---|
| `kind` | `first_response` \| `resolution` |
| `state` | `running` \| `paused` \| `warning` \| `breached` \| `met` \| `cancelled` |
| `target_minutes`, `warning_fraction` | copied from policy at start (policy edits don't silently change history) |
| `started_at` | |
| `paused_at`, `paused_total_seconds` | pause bookkeeping |
| `warning_at`, `due_at` | materialised deadlines; recomputed on resume and priority change |
| `met_at`, `breached_at` | |

The scheduler indexes on `(state, due_at)` and `(state, warning_at)` so a pass is one range scan.

## Rules

- **First response** timer is met by the first public agent comment. It is not started if the ticket is created by an agent on behalf of the contact (created in the UI) *and* the tenant setting `sla.first_response_applies_to_agent_created = false` (default `true`).
- **Resolution** timer is met on `resolved`; if reopened, a new resolution timer starts with the full target from the reopen time (Zendesk behaviour; documented in the algorithm page with the alternative).
- **Pause**: entering `pending` pauses both running timers. Resume adds the paused working time to `due_at` and `warning_at`.
- **Priority change**: the timer keeps `started_at` and gets the new target; `due_at = cal.add(started_at, new_target + paused_total)`. If that moment has already passed, the next check marks the timer breached.
- **Warning** fires once when `now ≥ warning_at` and the timer is `running`; **breach** fires once when `now ≥ due_at`. Both write `sla_events` and ticket history and send notifications.
- **Business calendars are in the MVP** ([ADR-0020](../adr/0020-business-calendars.md)): each policy references a `business_calendars` row (time zone, weekly windows, holidays) or none for 24×7; `due_at`/`warning_at` are computed through the calendar, pauses are measured in business seconds, and the timer stores `calendar_id` so later calendar edits do not move existing deadlines.
- The management API rejects changes to working hours, time zone or holidays while that Business calendar has running, warning or paused timers (409). Create a replacement calendar and move policies to it instead; existing timers retain their original calendar. Editing a policy increments its version and does not rewrite materialised timer deadlines.

## Presentation

How a timer reads to a person (M4-07, [components.md §SlaIndicator](../06-design-system/components.md#slaindicator)):

| Stored `state` | Shown as | Time part |
|---|---|---|
| `running` | On track | time left until `due_at` |
| `warning` | Due soon | time left until `due_at` |
| `breached` | Breached | time since `due_at` |
| `paused` | Paused, with "paused since" | none: the clock is stopped |
| `met` | Met, with the time it was met | none |
| `cancelled` | Cancelled (panel only) | none |

Every timer is labelled by kind (Response or Resolution). The panel names the policy and the version the timer started with and its Business calendar, because later edits do not move an existing deadline (rules above). The remaining time is wall-clock time to the stored `due_at`, which the calendar already placed; it is not a count of working minutes.

### Settings presentation (M4-12)

Settings → SLA policies lists each policy with its targets as a table per priority in **working time** (minutes and hours of the policy's Business calendar, never "days", because a day of working time is not 24 hours), the tier it applies to, the calendar and the warning point ("80% of the target"). Settings → Business calendars shows each calendar's working week with windows in words and the weekly total, and holidays as dates. Both editors warn before leaving with unsaved changes.

## Notifications on warning and breach

Warning: the assignee (or the team's members when unassigned). Breach: the assignee and the tenant's managers. Notifications are idempotent per timer and event. Automatic priority changes, reassignment and escalation chains are future replacements ([future/sla-evaluation-advanced.md](../05-algorithms/future/sla-evaluation-advanced.md)).

## Metrics derived

SLA compliance % = met / (met + breached) over resolved timers in the period, per kind; breaches in period; average first response and resolution times from ticket timestamps (excluding paused time in V1; MVP reports wall-clock and says so).
