# Ticket priority scoring — advanced design (future replacement candidate)

> **Status: not built in the MVP.** This is the richer design produced during research. The MVP ships the minimal academic baseline in [priority-scoring.md](../priority-scoring.md) behind a replaceable strategy interface ([ADR-0023](../../adr/0023-minimal-replaceable-algorithms.md)). After the project defence this design is one of the candidates for replacing the baseline.


Module: `App\Modules\Automation\Domain\Priority\PriorityScorer`. Deterministic, configurable, explainable. Requirements FR-AUT-01..03.

## 1. Background

- **ITIL 4** (AXELOS, *Incident Management: ITIL 4 Practice Guide*, 2020) defines priority as "the importance of a task relative to other tasks … in the context of all the tasks in a backlog" and states that "evaluating the impact and urgency of an incident … is not prioritization", prioritisation depending on "resource availability, target resolution time, and estimated processing time". A continuous score that blends impact/urgency with **deadline proximity** and **waiting time** is therefore closer to ITIL 4 than a static grid.
- The familiar **impact × urgency matrix** is a vendor practice: Jira Service Management's 4×4 matrix (Extensive…Minor × Critical…Low → Highest…Lowest), ServiceNow's priority lookup rules (data lookup table), GLPI's editable per-entity matrix. Our discrete P1–P4 output keeps compatibility with that practice.
- **Simple Additive Weighting (SAW / Weighted Sum Model)**: the elementary compensatory MCDM aggregation `S_i = Σ_j w_j · n_ij` ("Simple additive weighting — a metamodel for MCDA methods", *Expert Systems with Applications*, 2016; step-by-step guide 2023; arXiv:2509.06388 survey). Compensation is the desired semantics: a long-waiting low-impact ticket can outrank a fresh medium one.
- **Ageing** (Silberschatz, Galvin & Gagne, *Operating System Concepts*, ch. "CPU Scheduling"): priority scheduling starves low-priority tasks; the remedy is to raise priority with waiting time.
- **Deadline awareness**: Earliest Deadline First (Liu & Layland, *JACM* 1973) is optimal on one processor when importance is ignored; our SLA-proximity term is a normalised EDF signal blended with importance, a hybrid of EDF and priority scheduling.
- **Weight elicitation**: direct assignment with normalisation, or AHP (Saaty, *J. Math. Psychology* 1977) as an optional module with consistency ratio < 0.10.
- ML alternatives (Lê & Ait-Bachir, arXiv:2512.17916, F1 ≈ 0.785 with a fine-tuned transformer; Ticket-BERT, arXiv:2307.00108) reach moderate accuracy with no explainability; a deterministic score is defensible where agents must be able to contest the priority.

## 2. Inputs and outputs

| Input | Type | Source |
|---|---|---|
| `severity` | 1–4 (minor, moderate, major, critical) | ticket |
| `impact` | 1–4 (single user, team, department, organisation-wide) | ticket |
| `urgency` | 1–4 (low, medium, high, immediate) | ticket |
| `customer_tier` | standard / premium / enterprise | organisation |
| `affected_users` | integer ≥ 1 | ticket |
| `age_hours` | now − created_at (paused time excluded), hours | clock |
| `sla` | `{ started_at, due_at, paused_total }` of the resolution timer, or null at creation | SLA engine |
| `reopen_count` | integer | history |
| `settings` | weights, thresholds, saturation, mapping tables, version | tenant settings |

Output: `PriorityResult { score: float 0–100, level: P1..P4, breakdown: Contribution[], settings_version, computed_at }` where `Contribution { factor, raw, normalized, weight, contribution }` and `Σ contribution = score`.

## 3. Normalisation

Categorical inputs map to fixed ordinal values in [0,1] from a configuration table (not data-dependent min–max, which would make yesterday's score irreproducible):

| Factor | Mapping n(x) |
|---|---|
| severity, impact, urgency (1–4) | (x − 1) / 3 → 0, 0.333, 0.667, 1 |
| customer_tier | standard 0.3, premium 0.6, enterprise 1.0 |
| affected_users | log-saturating: `min(1, log10(users) / log10(U_sat))`, `U_sat = 1000` (1 user → 0; 10 → 0.33; 100 → 0.67; ≥1000 → 1) |
| age_hours | linear ageing `min(1, age_hours / AGE_SAT)`, `AGE_SAT = 72` |
| sla proximity | `clamp01(1 − slack / budget)` with `slack = due_at − now`, `budget = due_at − started_at − paused_total`; 1 when breached; 0 when no timer |
| reopen_count | `min(1, reopens / 3)` |

Guard: any division by zero (budget ≤ 0) yields 1 (treat as breached); documented and unit-tested.

## 4. Formula

```text
score = 100 · Σ_f w_f · n_f      with Σ w_f = 1, w_f ≥ 0
```

Default weights (baseline v1, tunable per tenant):

| factor | w |
|---|---|
| severity | 0.20 |
| urgency | 0.20 |
| impact | 0.15 |
| sla_proximity | 0.15 |
| customer_tier | 0.10 |
| age | 0.10 |
| affected_users | 0.05 |
| reopen | 0.05 |

Level thresholds (tenant setting): `score ≥ 75 → P1`, `≥ 55 → P2`, `≥ 30 → P3`, else `P4`.

Anti-oscillation rules:

1. **Automatic re-evaluation is monotone non-decreasing**: the hourly pass may raise the level, never lower it. Manual edits of the inputs recompute freely. This also resolves the circularity between priority and SLA target (raising priority shortens the target, which can only push the SLA term up, bounded by P1) — the process terminates.
2. **Demotion margin**: on manual recompute, a level drops only if the score is below the lower threshold by ≥ `demotion_margin` (3 points).
3. **Manual override** sets `priority_override_level`; the score is still computed and displayed, and escalation `raise_priority` acts on the effective level.

## 5. Pseudocode

```text
function score(t, now, settings):
    n = {}
    n.severity   = (t.severity - 1) / 3
    n.impact     = (t.impact - 1) / 3
    n.urgency    = (t.urgency - 1) / 3
    n.tier       = settings.tier_map[t.customer_tier]
    n.affected   = min(1, log10(max(1, t.affected_users)) / log10(settings.users_saturation))
    n.age        = min(1, hours(now - t.created_at - t.paused_total) / settings.age_saturation_hours)
    n.sla        = t.sla is null ? 0 : slaProximity(t.sla, now)
    n.reopen     = min(1, t.reopen_count / 3)
    contributions = []
    total = 0
    for f in settings.weights:                       // fixed order for determinism
        c = settings.weights[f] * n[f]
        contributions.append({f, raw[f], n[f], w[f], round(100*c, 4)})
        total += c
    score = round(100 * total, 2)
    level = thresholds(score, settings)               // P1..P4
    return PriorityResult(score, level, contributions, settings.version, now)

function slaProximity(sla, now):
    budget = seconds(sla.due_at - sla.started_at) - sla.paused_total
    if budget <= 0: return 1
    slack  = seconds(sla.due_at - now)
    return clamp01(1 - slack / budget)

function applyAutomatic(ticket, result):             // hourly pass
    if rank(result.level) > rank(ticket.level): raise level, record history, recompute SLA
    store score and breakdown regardless
```

## 6. Complexity

O(F) per ticket with F = 8 factors; the hourly pass is O(N) over open tickets in batches of 500 (1 000 open tickets ≈ 8 000 arithmetic operations plus one batched update). No external calls.

## 7. Worked examples

| Ticket | sev | imp | urg | tier | users | age h | sla | reopen | score | level |
|---|---|---|---|---|---|---|---|---|---|---|
| A: cosmetic typo, one standard user | 1 | 1 | 1 | std | 1 | 0 | none | 0 | 100·(0.10·0.3) = 3.0 | P4 |
| B: outage, org-wide, enterprise, 500 users | 4 | 4 | 4 | ent | 500 | 0 | none | 0 | 20+15+20+10+5·0.90 = 69.5 | P2 at creation; P1 after SLA term or 5 % more |
| B after 2 h with P2 target 8 h (slack 6 h) | | | | | | 2 | 0.25 | 0 | 69.5 + 15·0.25 + 10·(2/72) = 73.5 | P2 |
| B after 6 h (slack 2 h) | | | | | | 6 | 0.75 | 0 | 69.5 + 11.25 + 0.83 = 81.6 | **P1** (raised automatically) |
| C: medium bug, team, premium, waited 60 h | 2 | 2 | 2 | prem | 8 | 60 | 0.5 | 0 | 6.67+5+6.67+6+4.5·... ≈ 40 | P3 |

Example B shows the ITIL 4 property: the same incident becomes P1 as its resolution deadline approaches. Example rows are regenerated by the experiment command so the report's table matches the code exactly.

## 8. Configuration

`tenant_settings.data.automation.priority`: `weights` (validated: each 0–1, sum 1 ± 0.001), `thresholds` (strictly decreasing), `tier_map`, `users_saturation`, `age_saturation_hours`, `demotion_margin`, `version`. The settings UI shows a live preview of the sample tickets above.

## 9. Tests

- Unit: each normaliser at boundaries (0/1, saturation, zero budget); breakdown sums to score; weights order determinism; thresholds mapping; monotone automatic raise; demotion margin; override precedence; settings validation.
- Property (Pest datasets): score is monotone non-decreasing in every input; score ∈ [0,100].
- Feature: creating a ticket stores score/level/breakdown; hourly command raises a near-breach ticket; history row recorded.
- Snapshot: the worked-example table.

## 10. Experiment (E3 in [evaluation-methodology.md](../evaluation-methodology.md))

Scenario table over 12 tickets; one-at-a-time sensitivity of each weight over {0, 0.05, …, 0.5} with proportional renormalisation, reporting Spearman ρ against the baseline ranking and % level flips; ageing curve; weight-perturbation stability (±25 %). Optional: compare against agent-assigned priorities on a labelled sample with a 4×4 confusion matrix, macro-F1 and weighted Cohen's κ. Cite Saltelli et al. (2008) and state that OAT is appropriate because the model is additive.

## 11. Limitations and future work

Weights are elicited, not learned; AHP module optional; ML re-ranking (Laravel AI SDK) in V1 as a suggestion layer on top of the explainable score.
