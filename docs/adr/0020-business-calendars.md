# ADR-0020 Business-hours calendars and agent shifts in the MVP

**Status:** Accepted (2026-09-17). Changes FR-AUT-09 from Won't to Must and adds agent availability schedules.

## Context

The owner requires working days and shifts to be dynamic and settable per tenant rather than a fixed 24×7 assumption. The SLA design already isolates all time arithmetic behind a `BusinessCalendar` interface ([sla-evaluation.md](../05-algorithms/sla-evaluation.md)), and assignment eligibility already reads an agent `availability` field.

## Decision

1. **Tenant business calendars** are first-class: `business_calendars` (name, IANA time zone, weekly working windows per weekday, holidays with dates and optional recurrence, `is_default`). Each SLA policy references a calendar (or "24×7"). `WorkingHoursCalendar` implements `addDuration` and `elapsedBetween` by walking day segments; `TwentyFourSevenCalendar` stays the degenerate case, and both run the same test suite (DST transitions, holidays mid-timer, timer starting outside hours).
2. **Agent shifts**: `agent_shifts` (agent, weekday or date, start, end, time zone from the calendar) plus the existing manual `availability` status. An agent is *eligible for auto-assignment* when the manual status is `available` **and** the current time falls in a shift (or the tenant setting `shifts.enforce = false`). Managers can still assign manually outside shifts. "Next available agent" and shift-aware workload are V1.
3. **Settings UI**: Settings → Calendars (weekly grid editor, holiday list, time zone) and Agents → Shifts (weekly template per agent, exceptions by date). Dashboard SLA metrics use business time when the policy has a calendar.
4. Priority ageing uses **business hours** when the tenant default calendar is not 24×7 (`age_hours` = calendar-elapsed hours), so tickets do not age over weekends unless the tenant wants that.

## Alternatives considered

Keep 24×7 only (rejected by the owner); per-team calendars (V1; policy-level is enough now); full workforce management (V1).

## Consequences

Adds roughly one and a half days to the plan (calendar model + editor + shift eligibility + tests). The SLA scenario table gains calendar cases. The demo shows a tenant with a Sunday–Friday Nepal-time calendar and one 24×7 policy.

## Migration / future considerations

Rotating rosters, leave management, on-call escalation chains, per-team calendars.
