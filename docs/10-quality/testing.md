# Testing strategy

Decision: [ADR-0016](../adr/0016-testing-strategy.md). Week-by-week plan: [roadmap/05-testing-plan.md](../../roadmap/05-testing-plan.md). Definition of Done: [definition-of-done.md](definition-of-done.md).

## Test pyramid and target counts

| Level | Scope | Tool | Target count (end of M3) | Runs in |
|---|---|---|---|---|
| Backend unit | Actions, DTOs, enums, helpers, value objects, query builders | Pest 5 on PostgreSQL | ≈ 150 | every push |
| Algorithm unit and contract | baseline strategies (`BasicWeightedPriority`, `LeastLoadedAgent`, `JaccardDuplicates`, `SimpleSlaTimer`), calendars, history replay, interval builder, fairness metrics; one reusable contract suite per strategy interface | Pest 5, pure PHP, `FrozenClock` | ≈ 90 | every push |
| Feature / API | Every `/v1` endpoint: happy path, validation, problem-details shape, history/audit side effects, queued jobs dispatched | Pest 5 + Laravel HTTP testing | ≈ 120 | every push |
| Permission matrix | Routes × roles table | Pest datasets | ≈ 40 | every push |
| Tenancy isolation | Reflection, HTTP negatives, schema assertions, queue, channels, storage keys | Pest 5 | ≈ 30 | every push |
| Frontend unit | `lib/*`, Zod schemas, search-param serialisation, datetime, `can()` | Vitest 5 (node) | ≈ 40 | every push |
| Frontend component | DataTable server mode, TicketForm validation, Combobox pickers, ThemeProvider, PriorityExplanation | Vitest 5 browser mode + `vitest-browser-react`, MSW | ≈ 12 | every push |
| End-to-end | Golden path, isolation negative, theme persistence, webhook delivery, axe scans | Playwright 1.63 + `@axe-core/playwright` against Compose | ≈ 8 scenarios | PR to `main`, nightly |
| Experiments | Reproducibility (same seed ⇒ same hash) | Pest + artisan commands | 4 | M3, nightly |

Total ≈ 520 automated tests. Counts are targets; the actual numbers are reported in the table at the end of this page.

## Tools and configuration

| Concern | Choice | Notes |
|---|---|---|
| Backend runner | Pest 5 (PHPUnit 13) | `--parallel` locally; `--coverage` in CI with pcov |
| Backend database | PostgreSQL 18 service container, never SQLite | `RefreshDatabase` with transaction rollback; extensions (`pg_trgm`) created in `TestCase::setUpBeforeClass` |
| Static analysis | Larastan level 5 (M1) → 6 (M2+), Pint | fail CI on any error |
| Time | `App\Support\Clock` bound to `FrozenClock` in tests; `Carbon::setTestNow` never used directly | |
| Frontend runner | Vitest 5; node environment for `lib`, browser mode (Chromium via Playwright) for components | `@testing-library/react` not used; `vitest-browser-react` locators |
| API mocking | MSW 2 handlers typed from `schema.d.ts` | shared between Vitest and `pnpm dev --mock` |
| E2E | Playwright against `docker compose --profile demo up -d --wait`; official container in CI | trace on first retry, video off |
| Accessibility | `@axe-core/playwright` on login, dashboard, ticket list, ticket detail, settings/automation | fails on `serious`/`critical` |

## Conventions

- Layout: `backend/tests/{Unit,Feature,Isolation,Permissions,Architecture}` mirroring `app/Modules/<Module>`; algorithm tests live in `tests/Unit/Automation/*` and `tests/Unit/Sla/*`.
- Every factory sets `tenant_id` from the current tenancy context or an explicit `forTenant($tenant)` state; a factory that creates a tenant-scoped model without a tenant fails.
- Helpers in `tests/Pest.php`: `createTenant(string $slug = 'acme')`, `actingAsTenantUser(Tenant $t, string $role = 'agent')`, `actingAsApiClient(Tenant $t, array $scopes)`, `onApiHost()` (sets `Host: api.shp.test`), `loginToWorkspace(Tenant $t, User $u)` (real login through the API so the session carries `tenant_id`), `freezeAt('2026-09-01 09:00')`, `advance('2 hours')`.
- Feature tests always issue requests to the `api` host with a real session or bearer token so the whole middleware chain runs; direct `tenancy()->initialize()` only in unit tests.
- Datasets: Pest `dataset()` files under `tests/Datasets` for statuses, transitions, roles, and the SLA/priority scenario JSON in `experiments/datasets/v1/`.
- Every event-emitting action test asserts with `Event::fake([...])` narrowly, never `Event::fake()` globally, so listeners in other modules still run.
- Naming: `it('raises the level automatically when the SLA term pushes the score over the threshold')`.

## Mandatory tenancy isolation suite (`tests/Isolation`)

These tests are the proof for FR-TEN-03/04/05 and NFR-SEC-01. A failure blocks merge.

1. **Model reflection**: enumerate every class in `app/Modules/*/Models`; each must be in exactly one list (primary, secondary, global). For each primary and secondary model: seed rows in tenant A and B, query under tenant A, assert zero tenant-B rows; assert `create()` fills `tenant_id` with A.
2. **HTTP cross-tenant by ID**: for every resource type, user of A requests B's resource on A's host → 404; on B's host with A's session → 401 (membership).
3. **Host spoofing**: A's session with `Host: globex.helpdesk.test` → 401 `unauthenticated`; unknown host → 404 tenant not found.
4. **Schema assertions** from `information_schema`: every application-plane table has `tenant_id uuid NOT NULL`; every unique index on those tables includes `tenant_id`; RLS `ENABLE` and `FORCE` on every tenant table (from M3-RLS onward); runtime role is not owner and has `rolbypassrls = false`.
5. **RLS backstop**: with `app.current_tenant` set to A, a raw `DB::table('tickets')->count()` equals A's count; with it unset, zero rows.
6. **Queue**: a job dispatched inside tenant A's request runs with `tenant('id') === A` on the worker; a job dispatched centrally has no tenant; a job that switches tenant context re-initialises spatie permission cache.
7. **Broadcast channels**: user of A subscribing to `tenants.{B}.tickets` is denied; to `tenants.{A}.users.{otherUser}` is denied.
8. **Storage keys**: attachment intent for A produces a key under `tenants/{A}/`; a presigned download for B's attachment id from A → 404.
9. **Notifications table**: user in A never receives rows created for B (the `tenant_id` column added to `notifications` is asserted).
10. **Search**: `Ticket::search('outage')` under A returns none of B's matching tickets.
11. **Scheduler**: `sla:evaluate` processes timers of both tenants and emits events with the correct tenant context per timer.
12. **Suspended tenant**: all tenant routes return 403 `tenant_suspended`; platform routes unaffected.

## Permission matrix tests (`tests/Permissions`)

A table-driven test iterates `RouteMatrix` (route name × HTTP method × required permission) against the five default roles plus an API client with each scope. Expected outcome per cell is `200/201/204` (allowed), `403` (forbidden), or `skip` (record-level rule tested elsewhere). A separate route-list test asserts every registered `/api` route is either in the matrix or in the public allow-list (`auth/login`, `auth/accept-invitation`, `auth/forgot`, `oauth/token`, `up`, `health`, `docs/api*`). Adding a route without a matrix row fails CI.

## Algorithm test summary

| Algorithm | Unit tests (see algorithm page §Tests) | Scenario/experiment tests |
|---|---|---|
| Priority | scaled values at bounds, contributions sum to score, worked examples, thresholds, override precedence, settings validation | 12 scenarios; weight-change harness smoke; reproducibility hash |
| Assignment | each exclusion reason, lowest load wins, tie rules, round-robin rotation, determinism under shuffled input, empty set | workload replay smoke; Jain's index unit |
| Duplicates | word extraction, Jaccard at 0/1 and the worked example, threshold, ordering and limit, determinism | labelled pairs precision/recall at the default threshold (reported, no gate); cross-tenant candidates absent |
| SLA | transition table legality, pause/resume arithmetic, recompute from start, warning fraction, 24×7 and working-hours calendars, repeated checks notify once, policy selection | 10 timelines expected vs actual table |

**Contract suites.** `tests/Contracts/PriorityStrategyContract.php` and its siblings are Pest datasets any implementation must pass (determinism, bounds, explanation shape with `strategy` and `version`, empty input). A replacement strategy is added to the dataset and must pass before its binding is switched ([ADR-0023](../adr/0023-minimal-replaceable-algorithms.md)).

## CI gates

| Workflow | Steps | Gate |
|---|---|---|
| backend | composer validate, Pint `--test`, Larastan, Pest with coverage, `migrate --force` + `scramble:export`, `composer audit` | all green; coverage thresholds below |
| frontend | pnpm install (cache), Biome, `tsc --noEmit`, Vitest node, Vitest browser, build, `pnpm audit --audit-level=high` | all green |
| e2e | compose up `--wait`, demo seed, Playwright, axe | all green (PR to main and nightly) |
| security | gitleaks, dependency review | no findings |

Path filters keep backend/frontend workflows independent; the `e2e` job is required on `main`.

## Coverage targets

| Area | Line coverage | Enforced by |
|---|---|---|
| `app/Modules/Automation/Domain/*`, `app/Modules/Sla/Domain/*` | 100 % | Pest `--min` on those paths |
| Backend overall | ≥ 70 % | Pest `--coverage --min=70` |
| Frontend `lib/*` | ≥ 80 % | Vitest thresholds |
| Frontend overall | reported, not gated | |

## Reporting for the university testing chapter

Generated by `just test-report` (parses Pest/Vitest/Playwright JSON reporters) into `docs/10-quality/test-report.md` at the end of M3:

| Level | Tool | Tests | Passed | Failed | Skipped | Line coverage | Duration |
|---|---|---|---|---|---|---|---|
| Backend unit | Pest | | | | | | |
| Algorithm unit | Pest | | | | | 100 % target | |
| Feature/API | Pest | | | | | | |
| Permission matrix | Pest | | | | | — | |
| Tenancy isolation | Pest | | | | | — | |
| Frontend unit | Vitest | | | | | | |
| Frontend component | Vitest browser | | | | | — | |
| End-to-end | Playwright | | | | | — | |
| **Total** | | | | | | | |

Plus a sample-test-case table (ID, description, input, expected, actual, status) with ten representative cases per level, drawn from test names.

## Flakiness policy

A test that fails twice without a code change is quarantined the same day (`->skip('flaky: #issue')`), an issue is opened, and it must be fixed or deleted within two working days. Retries are allowed only in Playwright (one retry) and never in Pest/Vitest. Time-dependent tests must use `FrozenClock`; network-dependent tests are forbidden (MSW/Mailpit/webhook-echo only).
