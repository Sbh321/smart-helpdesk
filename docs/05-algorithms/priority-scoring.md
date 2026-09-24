# Priority scoring — Basic Weighted Priority (academic baseline)

Contract: `PriorityStrategy`. Baseline class: `App\Modules\Automation\Strategies\Baseline\BasicWeightedPriority` (`#[AcademicBaseline]`, `@deprecated` pointing to ADR-0023). Replaceable after the defence ([ADR-0023](../adr/0023-minimal-replaceable-algorithms.md)); richer candidate: [future/priority-scoring-advanced.md](future/priority-scoring-advanced.md). Requirements FR-AUT-01..03.

M2-04 is integrated and covered by feature tests; see [Integration as built](#integration-as-built-m2-04).

## Idea

A ticket's priority is a **weighted sum** of four things an agent can see: how much of the business is affected (impact), how quickly harm grows (urgency), how important the customer is (tier) and how long the ticket has waited (age). This is Simple Additive Weighting, the most basic multi-criteria decision method [SAW]; the age term is the "ageing" technique operating systems use so that waiting work is not starved [Silberschatz]. Impact and urgency are the inputs ITSM tools use for priority matrices [Jira, ServiceNow, GLPI]; ITIL 4 notes that priority should also consider the backlog, which the age term does in the simplest possible way [ITIL 4].

## Inputs

| Input | Values | Scaled value (0–1) |
|---|---|---|
| impact | 1 single user · 2 team · 3 department · 4 whole organisation | (impact − 1) ÷ 3 |
| urgency | 1 low · 2 medium · 3 high · 4 immediate | (urgency − 1) ÷ 3 |
| customer tier | standard · premium · enterprise | 0 · 0.5 · 1 |
| age | hours since creation, supplied by the caller as `hoursWaited` (business hours on the tenant's default calendar when it is not 24×7, "now" from the `Clock`) | min(1, hours ÷ `age_full_hours`, default 72) |

## Formula

```text
score = 100 × (0.40 × impact' + 0.35 × urgency' + 0.15 × tier' + 0.10 × age')

score = round(score, 1)            // after round(score, 6) removes float noise

level = P1 if score ≥ 75           // compared on the rounded score
        P2 if score ≥ 50
        P3 if score ≥ 25
        P4 otherwise
```

The level comes from the score rounded to one decimal, so the displayed score and the level always agree. The raw sum can carry float noise (for example 40.000000000000006); rounding to 6 decimals first removes it. A unit test pins the boundary case: impact 4, urgency 4, standard tier, 0 h gives exactly **75.0**, level P1.

Weights, thresholds and `age_full_hours` are tenant settings (`automation.priority.baseline`, class `PrioritySettings`):

| Setting | Default | Rule |
|---|---|---|
| `weights` | impact 0.40, urgency 0.35, tier 0.15, age 0.10 | exactly these four keys; each 0–1; sum 1 within 0.001 |
| `thresholds` | P1 75, P2 50, P3 25 | exactly these three keys; 100 ≥ P1 > P2 > P3 > 0 |
| `age_full_hours` | 72 | positive |

Invalid settings, impact or urgency outside 1–4, and a negative `hoursWaited` throw `InvalidStrategySettings`.

## Algorithm

```text
function score(ticket, now):
    parts ← [ ("impact",  0.40, (ticket.impact − 1) / 3),
              ("urgency", 0.35, (ticket.urgency − 1) / 3),
              ("tier",    0.15, tierValue(ticket.tier)),
              ("age",     0.10, min(1, hoursWaited(ticket, now) / 72)) ]
    score ← 0
    for (name, weight, value) in parts:
        contribution ← 100 × weight × value
        score ← score + contribution
        explanation.add(name, value, weight, contribution)
    score ← round(round(score, 6), 1)
    return score, level(score), explanation
```

**When it runs:** on ticket creation, when impact, urgency or organisation changes, and hourly for open tickets (because age grows). A manual priority chosen by a manager always wins over the computed level; the score is still shown. In code: `PriorityResult::effectiveLevel(?PriorityLevel $manual)` returns the manual level when there is one.

**Explanation:** `PriorityResult::explanation(?PriorityLevel $manual = null)` returns:

| Key | Value |
|---|---|
| `strategy`, `strategy_version` | `basic_weighted_priority`, `1.0.0` |
| `score` | rounded to one decimal |
| `level` | computed level |
| `effective_level` | manual level if given, else `level` |
| `manual_override` | `true` when a manual level was given |
| `parts` | per part: `name`, `value`, `weight`, `contribution`; value and contribution rounded to 4 decimals, not to 1 |
| `settings` | the weights, thresholds and `age_full_hours` used |

Because contributions are stored almost unrounded, the one-decimal parts in the table below do not always add up exactly to the score (13.3 + 23.3 + 7.5 + 3.3 = 47.4, score 47.5). The contract test accepts a difference up to 0.05.

**Complexity:** constant time per ticket (four terms); the hourly pass is linear in the number of open tickets.

### Integration as built (M2-04)

- Tickets knows nothing about scoring. `CreateTicket` and `UpdateTicket` (when impact or urgency changed) raise the synchronous `Tickets\Events\TicketPriorityInputsChanged` inside their transaction; `Automation\Listeners\ScorePriorityOnTicketInputs` calls `Actions\ScoreTicketPriority`, which is the only caller of the `PriorityStrategy` contract besides the preview.
- `ScoreTicketPriority` locks the ticket row, takes "now" from `Clock`, measures the waiting time on the workspace's default business calendar (24×7 when there is none) and stores `priority_score`, `priority_level` and `priority_explanation`. When the effective level moves it recomputes the SLA deadlines and writes a `priority_changed` history row plus the `PriorityChanged` event (actor `system` for the ageing pass); the first scoring of a new ticket writes no such row. A refreshed score alone does not move `updated_at`.
- `tickets:reevaluate-priority {--tenant=}` is the hourly ageing pass: every active workspace, tickets in `TicketStatus::active()` only (open, assigned, in progress, pending), chunks of 500, the age calendar resolved once per workspace.
- `POST /tickets/{ticket}/priority` (`tickets.update`), body `{level, reason}`: `level` must be present, `null` clears the override, `reason` is required with a level. The override always wins: later scoring refreshes the score and the computed level, `effective_level` stays, `manual_override` is `true` in the explanation. Each call writes a `priority_overridden` history row and the audit entry `ticket.priority_overridden` with the old and new override and effective level. A resolved or closed ticket answers 409 `conflict`.
- The ticket page (M4-06) reads `priority_explanation` and never recomputes it: a sentence with the total (the sum of the stored contributions) and the one or two largest factors, a bar per factor sized by its share of the score, and the factor table with strategy and version behind "Show the calculation".
- `POST /settings/automation/priority/preview` (`settings.manage`) scores up to 20 samples with unsaved weights, thresholds and `age_full_hours`; settings the strategy refuses answer 422. Nothing is stored.
- Settings come from `config/helpdesk.php` until the Settings service exists (M2-01); `priority_settings_version` is fixed at 1 (`MVP-SHORTCUT`).
- Feature tests: `tests/Feature/Automation/Priority/` (worked examples on create and preview, edit, ageing with `FrozenClock`, business calendar, override, permissions, cross-workspace 404).

## Worked examples

| Ticket | Impact | Urgency | Tier | Waited | Score | Level |
|---|---|---|---|---|---|---|
| Payment system down for all users | 4 | 4 | enterprise | 0 h | 40 + 35 + 15 + 0 = **90.0** | P1 |
| Report export slow for one department | 3 | 2 | standard | 0 h | 26.7 + 11.7 + 0 + 0 = **38.3** | P3 |
| Team cannot upload files | 2 | 3 | premium | 24 h | 13.3 + 23.3 + 7.5 + 3.3 = **47.5** | P3 |
| same ticket after 72 h | 2 | 3 | premium | 72 h | 13.3 + 23.3 + 7.5 + 10 = **54.2** | P2 |
| Typo on a help page | 1 | 1 | standard | 0 h | **0.0** | P4 |

The third and fourth rows show ageing: a ticket that waits long enough moves up one level.

## Tests

Scaled values at their bounds; score between 0 and 100; contributions add up to the score; each row of the table above; thresholds at exactly 25, 50, 75; manual override wins; settings with weights not adding up to 1 are rejected; the same input always gives the same output (contract test).

## Evaluation (experiment E3)

Scenario table as above for 12 tickets; one-at-a-time sensitivity: change each weight by ±0.1 (others rescaled) and count how many of 200 generated tickets change level; ageing curve for a P3 ticket from 0 to 96 hours.

**Results (M3-10, seed 42, dataset v1; tables T6–T7, plots 7–8, `experiments/results/v1/e3/`).** All **12 of 12** scenarios give the expected score and level: the five worked examples, the three exact thresholds (75.0 → P1, 50.0 → P2, 25.0 → P3), the 72-hour age cap and four mixed cases. With the default weights the 200 generated tickets split P1 25, P2 70, P3 75, P4 30.

| Weight changed | −0.1: tickets changing level (up / down) | +0.1: tickets changing level (up / down) |
|---|---|---|
| impact 0.40 | 29 (10 / 19) | 33 (16 / 17) |
| urgency 0.35 | 32 (15 / 17) | 28 (16 / 12) |
| tier 0.15 | **43** (32 / 11) | 22 (3 / 19) |
| age 0.10 | 21 (2 / 19) | 35 (27 / 8) |

A ±0.1 change moves 10.5–21.5 % of tickets by one level, so the levels are moderately sensitive to hand-chosen weights; lowering the tier weight has the largest effect (standard-tier tickets gain relative weight). The ageing ticket (impact 2, urgency 3, premium) rises from 44.2 at 0 h to P2 at **42 h** and stops at 54.2 after 72 h.

## Limitations and replacement

Weights are chosen by hand, not learned; age is the only backlog signal; the deadline of the SLA is not considered. Replacement options: the advanced multi-factor design, a rule engine, or a model trained on managers' overrides ([future](future/README.md)).
