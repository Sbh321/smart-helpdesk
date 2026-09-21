# Result analysis plan

The "Result Analysis" section of Chapter 4 is produced from reproducible experiments plus one performance measurement. Each experiment is run by `php artisan experiment:run <e1|e2|e3|e4|e6|all> --strategy=baseline --seed=42`, which takes the strategy under test by name ([ADR-0023](../adr/0023-minimal-replaceable-algorithms.md)); the experiment classes live in `backend/app/Modules/{Automation,Sla,Reporting}/Console/Experiments/` and write CSV/JSON plus `run.json` to `experiments/results/v1/<experiment>/` (committed). `experiments/plots.py` (matplotlib, [versions.md](../01-research/versions.md)) draws the plots as PNG and SVG; `just reproduce` regenerates everything. Commands, files and the table/plot list: [experiments/README.md](../../experiments/README.md).

## Status (M3-10, 2026-09-21)

| Experiment | Status | Headline result (seed 42, dataset v1) |
|---|---|---|
| E1 assignment | done | baseline: 0 capacity overflows (random 74, round robin 36); time-averaged Jain's index of utilisation 0.97 (random 0.74, round robin 0.80) |
| E2 duplicates | done | best threshold on the training split 0.30 → test precision 0.92, recall 0.77, F1 0.84 (default 0.35: F1 0.81); Recall@5 among 5 100 tickets 0.92 ranking only, 0.75 at 0.35; title-only word sets rank poorly (Recall@5 0.03) |
| E3 priority | done | 12/12 scenarios match; a ±0.1 weight change moves 10.5–21.5 % of 200 tickets to another level (most: tier −0.1); the ageing ticket reaches P2 after 42 h |
| E4 SLA | done | 10/10 timelines match (due and warning times, states, one warning and one breach per timer) |
| E5 performance | M3-11 | tables T9 and plot 9 are filled by [performance-testing.md](../10-quality/performance-testing.md) |
| E6 history | done (300 tickets, 90 days) | reconstruction 1 000/1 000 samples correct (backward and forward replay); incremental and rebuilt read models agree for 300/300 tickets and 90/90 snapshot days; heaviest report query p95 21 ms; change capture adds about 0.2 ms per ticket update |

## Tables and plots

Chapter 4.3 uses tables T1–T11 and plots 1–10 ([report-mapping.md](report-mapping.md)).

| Table | Content | Plot | Content |
|---|---|---|---|
| T1 | E1 policy comparison (final and time-averaged spread, Jain's index, overflows) | 1 | E1 final open tickets per agent per policy |
| T2 | E1 load per agent per policy | 2 | E1 load spread (std dev) over time |
| T3 | E2 threshold table 0.10–0.90 | 3 | E1 Jain's index over time |
| T4 | E2 best-F1 threshold on 70 %, reported on 30 % | 4 | E2 precision–recall curve |
| T5 | E2 title-only vs title + description, Recall@5 | 5 | E2 F1 by threshold |
| T6 | E3 scenario table | 6 | E2 score distribution by label |
| T7 | E3 weight-change table | 7 | E3 ageing curve |
| T8 | E4 SLA timeline table | 8 | E3 level changes per weight change |
| T9 | E5 latency per endpoint (M3-11) | 9 | E5 latency (M3-11) |
| T10 | E6 correctness | 10 | E6 report and update latency |
| T11 | E6 latency and trigger overhead | | |

## E1 — Assignment fairness

- **Dataset**: 8 agents with different skills and capacities, 6 categories, 500 tickets with seeded arrival times.
- **Policies compared**: random, round robin (skills respected), baseline least-loaded eligible agent.
- **Measures**: standard deviation, maximum and minimum of open tickets per agent (final and averaged over time), Jain's fairness index, capacity overflows (must be 0 for the baseline).
- **Output**: table (policy × measures), bar chart of final load per agent, line chart of load spread over time.

## E2 — Duplicate detection accuracy

- **Dataset**: 300 labelled pairs (100 duplicates, 200 non-duplicates including 50 from the same category).
- **Procedure**: Jaccard score per pair; thresholds 0.10–0.90; confusion counts, precision, recall, F1; best-F1 threshold chosen on 70 % and reported on 30 %; Recall@5 with the duplicates hidden among 5 000 tickets; title-only vs title-plus-description.
- **Output**: precision–recall curve, F1 vs threshold chart, threshold table, comparison table.

## E3 — Priority scoring behaviour

- **Dataset**: 12 scenario tickets and 200 generated tickets.
- **Procedure**: scenario scores and levels; each weight ±0.1 with the others rescaled; ageing curve 0–96 h.
- **Output**: scenario table, weight-change table (level changes per weight), ageing chart.

## E4 — SLA scenario verification

- **Dataset**: 10 scripted timelines (24×7 and working-hours calendars) with hand-computed expectations.
- **Procedure**: `experiment:run e4` runs each timeline through the `SlaStrategy` contract with a frozen clock and writes the expected-versus-actual table (the same examples are also unit tests).
- **Output**: scenario table; state diagram.

## E5 — Performance sanity

- **Setup**: seeded 20 tenants, one with 100 000 tickets; k6 script with 50 virtual users on list/detail/create endpoints for 5 minutes on a 2 vCPU / 4 GB VM (or the developer machine, stated).
- **Metrics**: p50/p95 latency per endpoint, error rate, SLA pass duration with 10 000 timers, DB index usage (`EXPLAIN ANALYZE` excerpts for the ticket list query).
- **Output**: table and one latency plot.

## E6 — History and reporting correctness

- **Procedure**: see [history-and-time-analytics.md](../05-algorithms/history-and-time-analytics.md) §9: reconstruction correctness over 1 000 random samples, incremental vs rebuilt read-model agreement, p95 latency of the heaviest reports, trigger write overhead. Runs on a `*_test` database only (it is migrated from scratch), 300 generated tickets over 90 days.
- **Output**: correctness table (expected 100 %), latency table, overhead percentage.

## Test evidence

Test counts per level and coverage report from CI (`pest --coverage`, `vitest --coverage`), tabulated in [10-quality/testing.md](../10-quality/testing.md).

## Reproducibility rules

Fixed seeds, dataset version in the result folder (`results/v1/`), command lines recorded in `experiments/README.md` and in every `run.json`, results committed. A reproducibility test runs E1–E4 twice with seed 42 and requires byte-identical files (`backend/tests/Feature/Experiments/ReproducibilityTest.php`).
