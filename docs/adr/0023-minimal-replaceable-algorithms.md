# ADR-0023 Minimal academic baseline algorithms behind replaceable strategies

**Status:** Accepted (2026-09-17). Replaces the detailed algorithm designs of the first planning round (kept in [05-algorithms/future](../05-algorithms/future/README.md)). Affects the decision summaries D17–D20 in [08-decisions-open-questions.md](../../roadmap/08-decisions-open-questions.md).

## Context

The algorithms exist mainly to satisfy the CACS452 requirement for self-written, relevant algorithms that the student can explain in the report and the viva. The owner wants them **very minimal and easy to understand**, and plans to **deprecate and replace them with better approaches after the project defence**. The earlier designs (eight-factor scores, relaxation ladders, TF-IDF with stemming, configurable SLA recomputation) were defensible but long to build, explain and test.

## Decision

### 1. Four minimal baselines

| Decision | Baseline | Core rule |
|---|---|---|
| Priority | **Basic Weighted Priority** | `score = 100 × (0.40·impact + 0.35·urgency + 0.15·tier + 0.10·age)` on values scaled to 0–1; P1 ≥ 75, P2 ≥ 50, P3 ≥ 25, else P4 |
| Assignment | **Least-Loaded Eligible Agent** | among available agents with the category's skills and free capacity, pick the lowest `open tickets ÷ capacity`; ties go to the agent assigned least recently |
| Duplicates | **Jaccard Duplicate Check** | word sets of title + description; `|A ∩ B| ÷ |A ∪ B|`; suggest candidates scoring ≥ 0.35 |
| SLA | **Simple SLA Timer** | `due = start + target` on the policy's calendar; warn at 75 %; pause while pending; recompute from the start when priority changes; notify on warning and breach |

Each fits on one page with a worked example ([05-algorithms](../05-algorithms/priority-scoring.md)). The history replay and interval sweep of the reporting module ([history-and-time-analytics.md](../05-algorithms/history-and-time-analytics.md)) are already minimal and stay. The inbound email `ReplyParser` is reduced to "cut at the first quote marker".

Removed from the MVP: severity and affected-user inputs, SLA-proximity and reopen terms, hysteresis, AHP; skill levels, affinity, penalties, relaxation ladder; stemming, TF-IDF, bigrams, structured-field bonuses, stored term statistics; per-metric pause settings, recompute modes, priority-raising escalation.

### 2. Replaceable by design

- Each decision is a contract in `App\Modules\Automation\Contracts`:

```php
interface PriorityStrategy   { public function score(PriorityInput $in): PriorityResult; }
interface AssignmentStrategy { public function choose(TicketNeeds $ticket, array $candidates): AssignmentResult; }
interface DuplicateStrategy  { public function find(TicketText $ticket, array $candidates): DuplicateResult; }
interface SlaStrategy        { /* start, pause, resume, complete, recompute, evaluate over TimerData */ }
```

- Baselines live in `App\Modules\Automation\Strategies\Baseline\*` (SLA: `App\Modules\Sla\Strategies\Baseline\*`), each marked with a `#[AcademicBaseline(replaceAfter: 'CACS452 defence')]` attribute and a `@deprecated` note pointing at this ADR.
- The binding is **platform configuration** (`config/helpdesk.php` → `strategies.priority` etc.), never a tenant setting, so replacing an algorithm is a one-line change plus a deployment.
- Every result carries `strategy` (name) and `strategy_version`; they are stored with the explanation so reports can compare old and new strategies over time.
- A **contract test suite** per interface (determinism, bounds, explanation shape, empty input, isolation of inputs) is written once and must pass for every future implementation.
- Experiments (`experiment:run`) take the strategy class as a parameter, so a replacement is evaluated on the same versioned datasets as the baseline before it is switched on.
- Settings (weights, thresholds) are namespaced per strategy (`automation.priority.baseline.*`) so a new strategy brings its own settings without migrating the old ones.

This is an interface with one implementation at first, which [P4](../00-project/principles.md) normally discourages; replacement is an explicit, dated requirement here, so the boundary is justified.

### 3. Deprecation path

After the defence: (1) implement a replacement behind the same contract; (2) run the contract tests and the experiments against both; (3) switch the binding; (4) keep the baseline for one release as a fallback, then delete it and its settings. Candidates are listed in [05-algorithms/future](../05-algorithms/future/README.md) and the V1 backlog.

## Alternatives considered

- Keep the detailed designs: stronger results, but more build time and harder to explain; rejected by the owner.
- Hard-code the baselines without contracts: simplest now, but replacement would touch callers; rejected.
- Tenant-selectable strategies: flexible, but a support and security burden; rejected for the MVP.

## Consequences

- Algorithm tasks shrink (about 2.5 effort-days saved) and the report's algorithm chapter becomes easier to write and defend.
- Results will be modest; the report presents them honestly as baselines and uses the literature review to motivate the replacements.
- Reports keep working across strategy changes because every decision records its strategy and version.

## Migration / future considerations

See §3. The advanced designs in `05-algorithms/future/` are the first replacement candidates.
