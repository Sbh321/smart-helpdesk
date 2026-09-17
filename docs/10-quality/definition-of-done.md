# Definition of Done

A roadmap task is done only when every applicable line below is true. "Renders in the browser" is not done. Shortcuts are allowed only with an `MVP-SHORTCUT` marker and a backlog item ([code-quality.md](code-quality.md)).

## Feature (any user-visible or API-visible change)

| # | Criterion | Evidence |
|---|---|---|
| 1 | The task's acceptance criteria and the linked FR-IDs are satisfied | manual walk-through + tests |
| 2 | Every new/changed endpoint declares a permission (`can:`) and appears in the permission matrix | `tests/Permissions` green |
| 3 | New tenant-scoped data has `tenant_id NOT NULL`, the correct trait, composite unique indexes, and the reflection isolation test covers it automatically | `tests/Isolation` green |
| 4 | Input validated in a FormRequest with tenant-scoped rules; JSONB validated against its schema | feature tests for invalid input |
| 5 | Errors follow [error-handling.md](../03-architecture/error-handling.md): problem details with a stable `code`; frontend maps 401/403/404/422/429 | test asserts `code` |
| 6 | Tests exist at the right levels (unit for logic, feature for endpoints, component for tricky UI, E2E only for golden-path steps) | CI |
| 7 | Documentation updated in the same change: the domain page, API conventions if the shape changed, ADR if a decision changed, runbook if operations changed | diff includes `docs/` |
| 8 | Accessibility: keyboard reachable and operable, labels/`aria-*` on controls, visible focus, contrast via tokens, `aria-live` for async status; axe scan clean on the route | manual check + axe |
| 9 | UI has loading, empty and error states and mutation feedback (toast/inline) | screenshots or component test |
| 10 | Migrations reversible (`down()` works) or explicitly marked irreversible with reason; RLS policy added for new tables (from M3) | `migrate:rollback` in CI job |
| 11 | `scramble:export` runs without warnings and `pnpm api:types` produces a committed diff reviewed for intent | CI |
| 12 | Any `MVP-SHORTCUT` marker has a V1 backlog item | `just shortcuts` |
| 13 | No new dependency without a research-page row and classification | review |
| 14 | Logs for the new path carry `request_id`/`tenant_id`; no PII in logs | review |
| 15 | Pint, Larastan, Biome, typecheck, all tests green in CI; branch merged to `main` | CI |

## Algorithm (priority, assignment, duplicates, SLA)

| # | Criterion |
|---|---|
| 1 | The algorithm page's pseudocode, formula, defaults and thresholds match the code (page updated if the code changed) |
| 2 | Pure domain class with explicit inputs/outputs; no Eloquent inside `Domain/` (arch test) |
| 3 | Explanation output (`breakdown`/`shortlist`/`exclusions`/`sla_events`) persisted and rendered in the UI "Why?" panel |
| 4 | Unit tests reach 100 % line coverage of the domain namespace; property/determinism tests included |
| 5 | Settings validated and versioned; results record the settings version |
| 6 | `php artisan experiment:<name> --seed=42` exists, writes CSV/JSON to `experiments/results/v1/`, and a test asserts identical output hashes for the same seed |
| 7 | Dataset committed under `experiments/datasets/v1/` with README (seed, counts, date) |
| 8 | Worked example in the doc is reproduced by a snapshot test |

## Roadmap week

| Week | Exit criteria |
|---|---|
| M1 | `docker compose up` gives login to two workspaces on `app.shp.localhost` and to the admin host; RBAC and isolation tests green; design tokens with light/dark/system live; contacts and ticket model exist with API; CI runs backend + frontend workflows; risk register reviewed |
| M2 | Golden path through the UI: create → auto-priority → duplicate suggestion → auto-assign → reply → pending → resolve; SLA warning/breach via `demo-tick`; notifications in Mailpit and bell; dashboard with six charts; algorithm unit tests at 100 %; Larastan level 6 |
| M3 | OAuth client + webhook delivery to webhook-echo verified; `/docs/api` live; RLS enabled with schema tests; experiments produce all tables/plots; Playwright + axe green in CI; k6 targets met or deviations documented; Ansible deploy to a fresh VM succeeds; demo seed and reset work; docs review checklist complete |

## MVP

- The [demo script](../12-academic/demo-plan.md) runs end to end on a laptop with no internet access, twice in a row after `just demo-reset`.
- CI green on `main`; test report table filled ([testing.md](testing.md)).
- Every Must-have in [mvp-scope.md](../02-product/mvp-scope.md) is done per this page; every Should-have is done or moved to the V1 backlog with a dated note.
- Documentation review checklist in [roadmap/06-documentation-plan.md](../../roadmap/06-documentation-plan.md) complete; ADR statuses current; open decisions resolved or listed.
- `experiments/results/v1/` contains tables T1–T11 and plots 1–10 with `run.json`.
- A fresh clone follows README to a running system in under 10 minutes (timed once).
