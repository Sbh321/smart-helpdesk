# Result analysis plan

The "Result Analysis" section of Chapter 4 is produced from four reproducible experiments plus one performance measurement. Each experiment is a Laravel artisan command that takes the strategy under test as a parameter ([ADR-0023](../adr/0023-minimal-replaceable-algorithms.md)), under `backend/Modules/*/Console/Experiments/` writing CSV/JSON to `experiments/results/` (committed) so tables and plots can be regenerated. Plots are produced by a small Python script (matplotlib) or by the SPA's chart components exported as SVG; the report needs PNG/SVG only.

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
- **Procedure**: Pest tests with a frozen clock produce an expected-versus-actual table.
- **Output**: scenario table; state diagram.

## E5 — Performance sanity

- **Setup**: seeded 20 tenants, one with 100 000 tickets; k6 script with 50 virtual users on list/detail/create endpoints for 5 minutes on a 2 vCPU / 4 GB VM (or the developer machine, stated).
- **Metrics**: p50/p95 latency per endpoint, error rate, SLA pass duration with 10 000 timers, DB index usage (`EXPLAIN ANALYZE` excerpts for the ticket list query).
- **Output**: table and one latency plot.

## E6 — History and reporting correctness

- **Procedure**: see [history-and-time-analytics.md](../05-algorithms/history-and-time-analytics.md) §9: reconstruction correctness over 1 000 random samples, incremental vs rebuilt read-model agreement, p95 latency of the heaviest reports, trigger write overhead.
- **Output**: correctness table (expected 100 %), latency table, overhead percentage.

## Test evidence

Test counts per level and coverage report from CI (`pest --coverage`, `vitest --coverage`), tabulated in [10-quality/testing.md](../10-quality/testing.md).

## Reproducibility rules

Fixed seeds, dataset version in output filename, command lines recorded in `experiments/README.md`, results committed.
