# Algorithm experiments

Reproducible measurements of the baseline algorithms for the report's result analysis (Chapter 4.3). Method: [docs/05-algorithms/evaluation-methodology.md](../docs/05-algorithms/evaluation-methodology.md); plan and status: [docs/12-academic/result-analysis-plan.md](../docs/12-academic/result-analysis-plan.md).

```text
experiments/
├── datasets/v1/        committed inputs; never regenerated in place (a change creates v2)
├── results/v1/<e>/     CSV tables, summary.json and run.json per experiment (committed)
├── results/v1/plots/   plots 1–10 as PNG and SVG (committed)
├── plots.py            renders the plots from the CSV tables (matplotlib)
└── requirements.txt    matplotlib pin (docs/01-research/versions.md)
```

## Commands

Everything is regenerated with one command from the repository root (about 40 s):

```bash
just reproduce                 # seed 42, E6 on helpdesk_test
just reproduce 42 helpdesk_c_test
```

It runs the steps below. PHP runs in a one-off `app` container with `experiments/` mounted at `/var/www/experiments` (the long-running `app` container only mounts `backend/`).

| Step | Command |
|---|---|
| Workload dataset (once) | `php artisan experiment:generate-workload --seed=42` |
| Duplicate pairs and haystack (once) | `php artisan experiment:generate-duplicates --seed=42` (haystack uses seed 43) |
| One experiment | `php artisan experiment:run e2 --strategy=baseline --seed=42` |
| All experiments | `php artisan experiment:run all --seed=42 --commit=$(git describe --always --dirty)` |
| Plots | `uv run --with-requirements experiments/requirements.txt python experiments/plots.py` |

For example, in Docker: `docker compose run --rm --no-deps -T -v "$PWD/experiments:/var/www/experiments" app php artisan experiment:run e1`.

Options of `experiment:run`: `--strategy=<name>` picks the strategy under test from `helpdesk.experiments.strategies.<decision>.<name>` in `backend/config/helpdesk.php` (`baseline` is the only name today; a replacement strategy adds its own name there and runs on the same datasets, ADR-0023 §2); `--seed`; `--output=<folder>`; `--commit=<id>` (recorded in `run.json`). The generators refuse to overwrite an existing dataset.

E1–E4 are pure PHP (strategies through their contracts, no database, frozen clock for E4). **E6 needs PostgreSQL: it runs `migrate:fresh` on the `*_test` database named by `EXPERIMENTS_DATABASE` (default `TEST_DATABASE`, else `helpdesk_test`) and refuses any other database.** Do not run it while a test suite uses the same database.

## Output of each run

Every experiment folder holds its tables (CSV), `summary.json` (headline numbers) and `run.json`: experiment, command, seed, dataset version, the strategy's name, version and class, the settings used, the list of output files and an `environment` block (git commit, PHP and PostgreSQL versions, time, duration). Two runs with the same seed give byte-identical files apart from `environment` for E1–E4 (checked by `backend/tests/Feature/Experiments/ReproducibilityTest.php`); E6 correctness numbers repeat, its latencies are wall-clock timings.

## Tables and plots

| Id | Experiment | Content | File |
|---|---|---|---|
| T1 | E1 | policy comparison: final and time-averaged std dev / max / min of open tickets, Jain's index (open tickets and utilisation), capacity overflows, unassigned | `e1/t1-assignment-policies.csv` |
| T2 | E1 | per agent: capacity, skills, final and average open tickets, tickets assigned, per policy | `e1/t2-final-load-per-agent.csv` |
| T3 | E2 | thresholds 0.10–0.90: TP, FP, FN, TN, precision, recall, F1 (both word-set variants, all 300 pairs) | `e2/t3-duplicate-thresholds.csv` |
| T4 | E2 | best-F1 threshold on the 70 % training split, measured on the 30 % test split, against the default 0.35 | `e2/t4-duplicate-held-out.csv` |
| T5 | E2 | title + description vs title only: mean scores, best threshold, test F1, Recall@5 among 5 100 tickets | `e2/t5-duplicate-variants.csv` |
| T6 | E3 | 12 priority scenarios: expected and actual score and level | `e3/t6-priority-scenarios.csv` |
| T7 | E3 | each weight ±0.1 (others rescaled): level changes among 200 tickets | `e3/t7-priority-weight-sensitivity.csv` |
| T8 | E4 | 10 SLA timelines: expected and actual state, warning and due times, notification counts | `e4/t8-sla-scenarios.csv` |
| T9 | E5 | performance: p50/p95 latency per endpoint, error rate — **filled by M3-11** ([performance-testing.md](../docs/10-quality/performance-testing.md)) | `e5/…` (M3-11) |
| T10 | E6 | history correctness: reconstruction (backward and forward replay), incremental vs rebuilt read models | `e6/t10-history-correctness.csv` |
| T11 | E6 | p50/p95/max latency of heavy report queries, as-of view, ticket update with and without change capture | `e6/t11-report-latency.csv` |

| Plot | Experiment | Content | Source |
|---|---|---|---|
| 1 | E1 | final open tickets per agent per policy, with capacities | T2 |
| 2 | E1 | std dev of open tickets over time per policy | `e1/load-over-time.csv` |
| 3 | E1 | Jain's index of utilisation over time per policy | `e1/load-over-time.csv` |
| 4 | E2 | precision–recall curve, both variants | T3 |
| 5 | E2 | F1 by threshold, both variants | T3 |
| 6 | E2 | score distribution of duplicates and non-duplicates | `e2/pair-scores.csv` |
| 7 | E3 | ageing curve of one ticket, 0–96 h | `e3/ageing-curve.csv` |
| 8 | E3 | level changes per weight change | T7 |
| 9 | E5 | API latency — **drawn once M3-11 writes `e5/latency.csv`** (columns `endpoint,p50_ms,p95_ms`) | T9 |
| 10 | E6 | report query and update latency | T11 |

## Where the code is

| Part | Location |
|---|---|
| Runner, seeded random numbers, metrics, output writer | `backend/app/Support/Experiments/` |
| E1–E3, dataset generators, random and round-robin policies | `backend/app/Modules/Automation/Console/Experiments/` |
| E4 | `backend/app/Modules/Sla/Console/Experiments/` |
| E6 | `backend/app/Modules/Reporting/Console/Experiments/` |
| Tests | `backend/tests/Unit/Experiments/`, `backend/tests/Feature/Experiments/` |

The feature tests that read the committed datasets skip themselves when `experiments/` is not visible (the long-running `app` container); run them with the mount: `docker compose run --rm --no-deps -T -e TEST_DATABASE=helpdesk_c_test -v "$PWD/experiments:/var/www/experiments" app vendor/bin/pest tests/Feature/Experiments`.
