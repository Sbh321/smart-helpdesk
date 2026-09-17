# SLA evaluation and escalation — advanced design (future replacement candidate)

> **Status: not built in the MVP.** This is the richer design produced during research. The MVP ships the minimal academic baseline in [sla-evaluation.md](../sla-evaluation.md) behind a replaceable strategy interface ([ADR-0023](../../adr/0023-minimal-replaceable-algorithms.md)). After the project defence this design is one of the candidates for replacing the baseline.


Module: `App\Modules\Sla\Domain\{SlaEngine, TimerState, BusinessCalendar, TwentyFourSevenCalendar}`, `App\Support\Clock`. Requirements FR-AUT-07..08. Data model: [04-domain/sla.md](../../04-domain/sla.md).

## 1. Background

- Helpdesk SLA metrics in practice: Zendesk (first reply, next reply, resolution; business or calendar hours; notably reply/resolution timers do **not** pause in Pending, only requester-wait/agent-work do), Jira Service Management (time metrics with OR-ed start/pause/stop conditions; reset creates a new cycle), ServiceNow (pause/resume conditions, retroactive start and pause), Zammad (first response / update / solution timers bound to a calendar; first response measured from creation and never reset), osTicket (grace period + transient flag).
- **Recomputation after a priority change** differs across vendors (ServiceNow retroactive from creation; Zammad from creation; Zendesk re-evaluates on next update; osTicket transient flag) — the justification for a configurable `recompute_mode`.
- **Business calendars**: Zammad's statement "if you define 8 hour business hours per day, a 16 hour SLA time interval will lead to a ticket escalation in 2 business days" is the arithmetic our `BusinessCalendar` interface encapsulates.
- **Statecharts** (Harel, *Science of Computer Programming* 1987): orthogonal regions model the workflow state and the SLA timer state running concurrently; UML state machines descend from them.
- **Escalation policies** (PagerDuty): ordered levels with timeouts; the MVP implements a reduced action list per policy and keeps the level model for V1.

## 2. Model

Two timers per ticket (`first_response`, `resolution`), each a state machine:

```mermaid
stateDiagram-v2
    direction LR
    state "Workflow (ticket)" as W {
        open --> in_progress
        in_progress --> pending
        pending --> in_progress
        in_progress --> resolved
        resolved --> closed
        resolved --> in_progress : reopen
    }
    --
    state "SLA timer" as S {
        [*] --> running : start(policy, priority)
        running --> paused : ticket pending [pauses_on_pending]
        paused --> running : resume (due_at += paused)
        running --> warning : now ≥ warning_at
        warning --> paused : pending
        paused --> warning : resume [now ≥ warning_at]
        warning --> breached : now ≥ due_at
        running --> breached : now ≥ due_at
        running --> met : target event
        warning --> met : target event
        breached --> met : target event (late)
        running --> cancelled : ticket closed as duplicate / n/a
        met --> running : reopen [resolution timer, new cycle]
    }
```

Target events: `first_response` is met by the first public agent comment; `resolution` by transition to `resolved`.

Timer fields (materialised): `target_minutes`, `started_at`, `paused_at`, `paused_total_seconds`, `warning_at`, `due_at`, `warned_at`, `breached_at`, `met_at`, `cycle` (increments on reopen), `policy_version`, `calendar_id` (null = 24×7).

## 3. Calendar and clock interfaces

```php
interface Clock { public function now(): CarbonImmutable; }               // SystemClock | FrozenClock (tests)
interface BusinessCalendar {
    public function addDuration(CarbonImmutable $from, int $seconds): CarbonImmutable;
    public function elapsedBetween(CarbonImmutable $a, CarbonImmutable $b): int;
}
final class TwentyFourSevenCalendar implements BusinessCalendar { /* plain arithmetic */ }
```

Every due-time computation goes through the calendar. The MVP ships both `TwentyFourSevenCalendar` and `WorkingHoursCalendar` (weekly windows per weekday, holiday dates, IANA zone; `addDuration` walks day segments and skips closed periods, `elapsedBetween` sums open time), selected per SLA policy ([ADR-0020](../../adr/0020-business-calendars.md)). Both run the same test suite, plus DST-transition, holiday-mid-timer and start-outside-hours cases.

## 4. Rules

| Event | Effect |
|---|---|
| Ticket created | select policy (org tier → default); for each kind: `target = policy.target(kind, level)`, `started_at = now`, `due_at = cal.add(started_at, target)`, `warning_at = cal.add(started_at, target × warning_fraction)`; state `running` |
| Ticket → pending | for timers with `pauses_on_pending` (default: both true; per-metric setting): `paused_at = now`, state `paused` (remember previous sub-state) |
| Ticket leaves pending | `paused_total += elapsed(paused_at, now)`; `due_at = cal.add(due_at, elapsed)`; `warning_at` likewise; state back to `running`/`warning` by comparing now |
| First public agent comment | `first_response` → `met`, `met_at = now` (late if previously breached; recorded) |
| Ticket → resolved | `resolution` → `met` |
| Reopen | new `resolution` cycle: `started_at = now`, full target (Zendesk/Jira "new cycle" semantics); `first_response` unchanged |
| Priority level change | `recompute_mode = from_start` (default): keep `started_at`, `target = policy.target(kind, newLevel)`, `due_at = cal.add(started_at, target) + paused_total`; if `due_at ≤ now` → state `breached` with event `breached_on_escalation` (ServiceNow's retroactive problem, named). `from_change`: `started_at = now`, `paused_total = 0` |
| Policy edited | affects new tickets; existing running timers keep their copied targets unless admin chooses "apply to open tickets" (recompute as above; audited) |
| Closed as duplicate | both timers `cancelled` |
| Sweep (every minute) | `running → warning` when `now ≥ warning_at` (once, sets `warned_at`); `running|warning → breached` when `now ≥ due_at` (once, sets `breached_at`); each transition writes `sla_events`, ticket history, dispatches `SlaWarning`/`SlaBreached`, runs escalation actions |

## 5. Escalation actions

Policy JSON: `{ on_warning: ['notify_assignee'], on_breach: ['notify_managers', 'raise_priority'] }`. Actions are idempotent per `(timer_id, event)`: `notify_assignee`, `notify_team`, `notify_managers`, `raise_priority` (one level, max P1, recorded as override reason `sla_escalation`, which triggers SLA recompute → may not breach again because it is already breached), `reassign` (Could-have). Level/timeout escalation chains are V1.

## 6. Pseudocode

```text
function evaluate(now):                                  // sla:evaluate, every minute, onOneServer
    for timer in query(state in (running, warning) and (warning_at ≤ now or due_at ≤ now))
                  FOR UPDATE SKIP LOCKED, batches of 500:
        if timer.state == running and now ≥ timer.warning_at and timer.warned_at is null and now < timer.due_at:
            transition(timer, warning, now); emit SlaWarning
        if now ≥ timer.due_at and timer.breached_at is null:
            transition(timer, breached, now); emit SlaBreached; runEscalation(timer.policy.on_breach)

function pause(timer, now):   if timer.state ∈ {running, warning} and timer.pauses_on_pending: timer.paused_at = now; timer.state = paused
function resume(timer, now):  d = cal.elapsed(timer.paused_at, now); timer.paused_total += d
                              timer.due_at = cal.add(timer.due_at, d); timer.warning_at = cal.add(timer.warning_at, d)
                              timer.state = now ≥ timer.warning_at ? warning : running; timer.paused_at = null
function recompute(timer, newTarget, now, mode):
    if mode == from_change: timer.started_at = now; timer.paused_total = 0
    timer.target_minutes = newTarget
    base = cal.add(timer.started_at, newTarget·60)
    timer.due_at = cal.add(base, timer.paused_total); timer.warning_at = cal.add(timer.started_at, newTarget·60·warning_fraction) + paused_total
    if timer.due_at ≤ now and timer.breached_at is null: transition(timer, breached, now, reason='breached_on_escalation')
    record sla_events {type:'recomputed', old_due, new_due}
```

All transitions are in one table-driven function that rejects illegal moves (`InvalidTimerTransition`), so the state machine is explicit and testable.

## 7. Complexity

The sweep is one indexed range scan on `(state, due_at)` / `(state, warning_at)` plus O(D) updates for D due timers; 10 000 running timers with 50 due per minute costs milliseconds. Pause/resume/recompute are O(1) per timer.

## 8. Worked scenarios (E4)

| # | Timeline | Expected |
|---|---|---|
| 1 | P2 created 09:00 (FR 60 min, RES 8 h); agent reply 09:40 | FR met 09:40; RES warning 15:00, due 17:00 |
| 2 | as 1; pending 10:00–12:00 | RES due 19:00, warning 17:00 |
| 3 | as 1; no reply | FR warning 09:45, breach 10:00 (once) |
| 4 | P3 created 09:00 (RES 24 h); raised to P1 (4 h) at 14:00, from_start | due 13:00 < now → `breached_on_escalation` at 14:00 |
| 5 | as 4, from_change | started 14:00, due 18:00 |
| 6 | resolved 12:00; reopened 13:00 | RES cycle 2 started 13:00, due 21:00 |
| 7 | closed as duplicate | both cancelled |
| 8 | pending → resolved directly | resume then met; paused time excluded |
| 9 | breach then reply | FR met late; breached_at kept |
| 10 | sweep run twice at same minute | events emitted once |

Each row is a Pest test with `FrozenClock`; the test writes the expected/actual table used in the report.

## 9. Tests

Unit: transition table legality; pause/resume arithmetic including multiple pauses; recompute modes; warning fraction; calendar interface with 24×7; idempotent sweep; escalation actions idempotent; policy selection by tier. Feature: end-to-end scenarios above through the HTTP API; notifications and webhooks emitted; history rows.

## 10. Limitations

Calendar time only (business hours V1); no "next reply time"/periodic update timers (V1); escalation levels/timeouts (V1); reporting averages use wall-clock and say so.
