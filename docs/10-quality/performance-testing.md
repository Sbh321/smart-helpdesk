# Performance testing

Targets: [02-product/non-functional-requirements.md](../02-product/non-functional-requirements.md) §Performance. Runs in M3 (task M3 performance sanity) on the developer machine and, if available, on the reference cloud VM (2 vCPU / 4 GB); the machine used is stated in the results.

## Targets (tested MVP scale)

| ID | Scenario | Target |
|---|---|---|
| NFR-PERF-01 | ticket list, 25 rows, filters + sort, tenant with 100 000 tickets among 20 tenants | p95 < 300 ms |
| NFR-PERF-02 | ticket create incl. priority, duplicate detection (K ≤ 50), assignment | p95 < 800 ms |
| NFR-PERF-03 | `sla:evaluate` pass with 10 000 running timers, 50 due | < 5 s |
| NFR-PERF-04 | dashboard aggregates (cached 60 s; cold) | p95 < 1 s |
| NFR-PERF-05 | 50 concurrent virtual users, 5 min | error rate < 1 % |

**Architectural targets** (not measured in the MVP, stated separately in the report): 1 M tickets per tenant, hundreds of concurrent agents, horizontally scaled app and workers. Claims about them are limited to "the design does not preclude" with the reasoning in [roadmap/10-future-architecture.md](../../roadmap/10-future-architecture.md).

## Seeding 100 000 tickets

`php artisan perf:seed --tenants=20 --tickets=100000 --seed=7`: creates 20 tenants with 8 agents each; the first tenant receives 100 000 tickets, the rest 2 000 each. Rows are generated in PHP in chunks of 5 000 and inserted with `DB::table()->insert()` using `uuidv7()` generated in SQL (`DEFAULT` expression on the insert) so the factory pipeline is bypassed; `ticket_events`, `ticket_sla_timers` (two per ticket) and the reporting read models are bulk-inserted too. Runtime target < 3 min. The seed runs with RLS disabled via the owner role and re-enables it afterwards.

## k6 script outline (`experiments/perf/k6.js`)

```js
export const options = {
  scenarios: {
    list:      { executor: 'constant-vus', vus: 25, duration: '5m', exec: 'list' },
    detail:    { executor: 'constant-vus', vus: 15, duration: '5m', exec: 'detail' },
    create:    { executor: 'constant-vus', vus: 5,  duration: '5m', exec: 'create' },
    dashboard: { executor: 'constant-vus', vus: 5,  duration: '5m', exec: 'dashboard' },
  },
  thresholds: {
    'http_req_duration{name:list}':      ['p(95)<300'],
    'http_req_duration{name:detail}':    ['p(95)<300'],
    'http_req_duration{name:create}':    ['p(95)<800'],
    'http_req_duration{name:dashboard}': ['p(95)<1000'],
    http_req_failed: ['rate<0.01'],
  },
};
```

Each scenario logs in once per VU (Sanctum cookie jar), then loops: `list` rotates through ten filter/sort combinations and pages 1–5; `detail` opens random ticket ids from the list response; `create` posts a ticket with a title drawn from the duplicate-cluster titles (exercises candidates); `dashboard` hits `/v1/dashboard` and one chart endpoint. Results exported with `--out json` to `experiments/results/v1/perf/`.

## Query verification checklist (`EXPLAIN (ANALYZE, BUFFERS)`)

| Query | Expected plan | Index |
|---|---|---|
| Ticket list default (open, sort by `priority_score DESC, id`) | Index Scan on `(tenant_id, status, priority_score DESC, id)` partial where status not in (resolved, closed) | `tickets_open_priority_idx` |
| Ticket list with search term | Bitmap AND of GIN `search_vector` and btree `tenant_id` | `tickets_search_gin` |
| Ticket list by assignee/team | `(tenant_id, assigned_agent_id, status)` | |
| Duplicate candidate query | Bitmap Index Scan on trigram GIN for `title`, filtered by tenant/status/created_at; ≤ 50 rows; < 30 ms | `tickets_title_trgm` |
| SLA sweep | Index Range Scan on `(state, due_at)` and `(state, warning_at)` with `SKIP LOCKED` | `sla_timers_due_idx` |
| Dashboard counts by status/priority | single `GROUP BY` over `(tenant_id, status)`; < 100 ms at 100k | |
| Contact typeahead | trigram GIN on `contacts.name`/`email` | |

Any Seq Scan on `tickets` in these plans is a defect to fix before the demo. Plans are saved as text in `experiments/results/v1/perf/plans/`.

## Background work

- SLA sweep timing: `php artisan sla:evaluate --measure` prints duration with 10 000 running timers; run three times, report median.
- Duplicate detection timing inside create is measured with a `Timing` middleware header (`Server-Timing: priority;dur=…, duplicates;dur=…, assignment;dur=…`) captured by k6.
- Webhook fan-out: 100 deliveries to `webhook-echo`, measure queue drain time (< 30 s with 2 workers).

## Process sizing (reference VM)

| Process | Setting | Rationale |
|---|---|---|
| PHP-FPM | `pm=dynamic`, `max_children=12`, `start_servers=4`, memory limit 256 MB | 12 × ~60 MB < 1 GB leaves room for PostgreSQL |
| Horizon | supervisor `default`: 2 processes; `webhooks`+`notifications`: 2; `sla`: 1; `exports`: 1 | keeps SLA sweep isolated from bursts |
| PostgreSQL | `shared_buffers=512MB`, `work_mem=16MB`, `max_connections=50` | app + workers never exceed 30 connections |
| Valkey | `maxmemory 256mb`, `allkeys-lru` for cache DB only | queues in a separate logical DB |

## Results table template

| Scenario | VUs | Requests | p50 ms | p95 ms | p99 ms | Errors % | Target | Pass |
|---|---|---|---|---|---|---|---|---|
| list | 25 | | | | | | 300 | |
| detail | 15 | | | | | | 300 | |
| create (with automation) | 5 | | | | | | 800 | |
| dashboard | 5 | | | | | | 1000 | |
| SLA sweep (10k timers) | — | 3 runs | median s | | | | 5 s | |

Recorded with machine spec, PostgreSQL/PHP versions, git SHA, dataset seed, and date in `experiments/results/v1/perf/run.json`.

## When and how

1. M3 day 3: seed, run `EXPLAIN` checklist, fix indexes.
2. M3 day 4: k6 run, sweep timing, fill the table; if a target fails, the fix is limited to indexes, query shape, cache TTLs or process counts — no architectural changes in M3.
3. Results feed E5 in [12-academic/result-analysis-plan.md](../12-academic/result-analysis-plan.md).
