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

## Timers

Each ticket gets two timer rows in `ticket_sla_timers`:

| Field | Notes |
|---|---|
| `kind` | `first_response` \| `resolution` |
| `state` | `running` \| `paused` \| `warning` \| `breached` \| `met` \| `cancelled` |
| `target_minutes` | copied from policy at start (policy edits don't silently change history) |
| `started_at` | |
| `paused_at`, `paused_total_seconds` | pause bookkeeping |
| `warning_at`, `due_at` | materialised deadlines; recomputed on resume and priority change |
| `met_at`, `breached_at` | |

The scheduler indexes on `(state, due_at)` and `(state, warning_at)` so a pass is one range scan.

## Rules

- **First response** timer is met by the first public agent comment. It is cancelled if the ticket is created by an agent on behalf of the contact *and* the tenant setting `first_response_applies_to_agent_created = false`.
- **Resolution** timer is met on `resolved`; if reopened, a new resolution timer starts with the full target from the reopen time (Zendesk behaviour; documented in the algorithm page with the alternative).
- **Pause**: entering `pending` pauses both running timers. Resume adds the paused working time to `due_at` and `warning_at`.
- **Priority change**: the timer keeps `started_at` and gets the new target; `due_at = cal.add(started_at, new_target + paused_total)`. If that moment has already passed, the next check marks the timer breached.
- **Warning** fires once when `now ≥ warning_at` and the timer is `running`; **breach** fires once when `now ≥ due_at`. Both write `sla_events` and ticket history and send notifications.
- **Business calendars are in the MVP** ([ADR-0020](../adr/0020-business-calendars.md)): each policy references a `business_calendars` row (time zone, weekly windows, holidays) or none for 24×7; `due_at`/`warning_at` are computed through the calendar, pauses are measured in business seconds, and the timer stores `calendar_id` so later calendar edits do not move existing deadlines.

## Notifications on warning and breach

Warning: the assignee (or the team's members when unassigned). Breach: the assignee and the tenant's managers. Notifications are idempotent per timer and event. Automatic priority changes, reassignment and escalation chains are future replacements ([future/sla-evaluation-advanced.md](../05-algorithms/future/sla-evaluation-advanced.md)).

## Metrics derived

SLA compliance % = met / (met + breached) over resolved timers in the period, per kind; breaches in period; average first response and resolution times from ticket timestamps (excluding paused time in V1; MVP reports wall-clock and says so).
