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
| End-to-end | Golden path, isolation negative, theme persistence, webhook delivery, API client, invitations, reports, axe scans | Playwright 1.63 + `@axe-core/playwright` against Compose | ≈ 8 scenarios (as built: 9 scenarios + 32 axe scans) | PR to `main`, push to `main`, nightly |
| Experiments | Reproducibility (same seed ⇒ same hash) | Pest + artisan commands | 4 | M3, nightly |

Total ≈ 520 automated tests. Counts are targets; the actual numbers are reported in the table at the end of this page.

## Tools and configuration

| Concern | Choice | Notes |
|---|---|---|
| Backend runner | Pest 5 (PHPUnit 13) | `--coverage` with pcov: in CI, and locally with `just coverage` (pcov is in the development image, disabled until that recipe turns it on; M2 exit review) |
| Backend database | PostgreSQL 18 service container, never SQLite | `RefreshDatabase` with transaction rollback; extensions (`pg_trgm`) created in `TestCase::setUpBeforeClass` |
| Static analysis | Larastan level 5 (M1) → 6 (M2+), Pint | fail CI on any error |
| Time | `App\Support\Clock` bound to `FrozenClock` in tests; `Carbon::setTestNow` never used directly | |
| Frontend runner | Vitest 5; node environment for `lib`, browser mode (Chromium via Playwright) for components | `@testing-library/react` not used; `vitest-browser-react` locators |
| API mocking | MSW 2 handlers typed from `schema.d.ts` | shared between Vitest and `pnpm dev --mock` |
| E2E | Playwright on the host (or CI runner) against the Compose stack with the `dev`, `storage` and `demo` profiles; no Playwright container | trace and screenshot kept on failure, video off; §End-to-end |
| Accessibility | `@axe-core/playwright` on 16 screens (login, dashboard, ticket list, ticket detail, dialogs, reports, settings pages) in light and dark | fails on `serious`/`critical` |

## Conventions

- Layout: `backend/tests/{Unit,Feature,Isolation,Permissions,Architecture}` mirroring `app/Modules/<Module>`; algorithm tests live in `tests/Unit/Automation/*` and `tests/Unit/Sla/*`.
- Every factory sets `tenant_id` from the current tenancy context or an explicit `forTenant($tenant)` state (the `Database\Factories\Concerns\ForTenant` trait); a factory that creates a tenant-scoped model without a tenant fails on the NOT NULL column, which the isolation suite asserts per model.
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

### Status (v1, M1-10)

The suite is its own PHPUnit test suite (`<testsuite name="Isolation">`, `vendor/bin/pest tests/Isolation`) and runs in about four seconds. Version 1 covers the items that the M1 models and tables allow; the rest are written by the task that brings the surface they guard, in the same numbering.

| Item | State | Where |
|---|---|---|
| 1 Model reflection | done | `tests/Isolation/ModelReflectionTest.php` — every concrete Eloquent class under `app/` is found on disk and must appear in exactly one list of `Tests\Support\TenantModelInventory` (primary, secondary, nullable, credentials, central), its list must match the trait it uses, its table must be registered in `TenantTables`, and a table with a `tenant_id` column that is in neither the registry nor the documented control-plane allow-list fails. A model added without a tenant trait therefore fails before it can be used. |
| 1 Data isolation | done for models with a factory | `tests/Isolation/ModelDataIsolationTest.php` — datasets over the primary list: zero rows of B under A, `find`/`update`/`delete` of a B key unreachable, `create()` stamps A, a factory with no tenant fails (row-level security refuses the row before the NOT NULL check). Secondary models join in M2 with `BelongsToPrimaryModel`. |
| 2 HTTP cross-tenant by ID | done for `users` | `tests/Isolation/CrossTenantHttpTest.php` — 404 on read and write, a cross-tenant id indistinguishable from an unknown one, a list route that returns only the session tenant, 401 for a bearer token whose tenant is not its owner's and for a swapped session tenant. The M2 resources join by adding their routes to the same file. |
| 3 Host spoofing | not applicable | Hosts never identify tenants ([ADR-0021](../adr/0021-host-layout-and-tenant-resolution.md)). The equivalent negatives — session tenant ≠ user tenant, no session tenant, deleted tenant, unknown or malformed workspace, single-tenant mode — are in `tests/Feature/Tenancy/TenantResolutionTest.php`. |
| 4 Schema assertions | done | `tests/Isolation/SchemaTest.php` — read from `information_schema`, `pg_index`, `pg_constraint`, `pg_trigger` and `pg_roles`: `tenant_id uuid NOT NULL` with a foreign key to `tenants`, every unique index other than the surrogate primary key led by `tenant_id`, the `protectTenantId()` trigger present, and a runtime role that is neither table owner nor superuser and has `rolbypassrls = false`. |
| 4 RLS enable/force/policies, 5 RLS backstop | done (M3-07) | `tests/Isolation/RowLevelSecurityTest.php` — `relrowsecurity` and `relforcerowsecurity` and a `tenant_isolation` policy for every `TenantTables::all()` row (a new table without its policy fails here), no other policy but `global_roles_read`, no RLS on the credential and control-plane tables, no `TRUNCATE` for the app role; fail-closed (a raw `DB::table('tickets')->count()` outside a tenant is 0) and the raw-query backstop (inside A it equals A's count); inserts for another tenant refused with `42501`, updates and deletes of its rows reach nothing; every seedable model's row of B invisible to a raw query in A; the nullable policies of `audit_logs` and `roles`; the setting cleared on end, restored after a failing tenant run inside a transaction, re-applied after a rollback, and never carried from a failing tenant job into the next central job on one worker connection. |
| 6 Queue | done | `tests/Isolation/QueueContextTest.php` — jobs for A, B and the centre in one worker run: each sees its own tenant, `app.current_tenant`, permission team and rows; the tenant travels in the job payload. The bootstrapper plumbing (re-initialise, `end()` after the job, log context) stays in `tests/Feature/Tenancy/TenancyBootstrapTest.php`. |
| 7 Broadcast channels | M3-16 | `tests/Feature/Realtime/ChannelAuthorizationTest.php`: every channel shape of workspace A refused to a user of B, another user's bell channel refused, the internal-note channel only with `comments.internal`, API clients refused. |
| 8 Storage keys | partly, M2 | The `tenants/{id}/` disk prefix is asserted in `TenancyBootstrapTest.php`; the presigned-download negative arrives with the Media module. |
| 9 Notifications, 10 Search, 11 Scheduler | M2 | Need the tables and commands. |
| 12 Suspended tenant | done | `TenantResolutionTest.php` (403 `tenant_suspended` on tenant routes, platform routes unaffected). |

Two registry gaps are stepped over by name in `Tests\Support\TenantModelInventory`, each with the task that closes it, so that every *other* table is still enforced: `invitations` is missing from `TenantTables::PRIMARY` (M1-08) and `entity_changes_version_unique` does not lead with `tenant_id` (M1-09). The exemptions clear themselves as soon as the owning task fixes the table.

### Writing tests under row-level security (M3-07)

The suite connects as `helpdesk_app`, so the policies apply to the test body as well:

- **Every request starts in the central context**, as a fresh PHP-FPM request does, and the test body gets its own context back afterwards (`Tests\TestCase::call()`). A tenant left over in the test body can never leak into the request under test.
- **`actingAsTenantUser()` and `actingAsRole()` put the test body inside the tenant**, so assertions on that workspace's rows work as before. A test without them initialises the tenant it asserts on (`tenancy()->initialize($tenant)`, often in `beforeEach`).
- **Rows of another workspace** are read with `$other->run(fn () => …)`; `findInAnyTenant(Model::class, $id)` finds a row by id in whichever workspace holds it. `withoutTenancy()` and `withoutGlobalScopes()` only remove the Eloquent scope; they no longer reach other tenants.
- **Factories store each row inside its own tenant** (`ForTenant::store()` runs the insert in `$tenant->run()` when the row's tenant is not the current one), so `forTenant($b)` works from any context. A raw `DB::table()->insert()` of tenant rows needs the tenant initialised.
- A statement expected to be refused goes inside `DB::transaction()` (a savepoint), so the refusal does not abort the test's own transaction.
- An in-process `$this->artisan()` command inherits the test body's context; commands that loop tenants restore it afterwards.

## End-to-end (`frontend/e2e`, M3-12)

**Run.** With the stack up: `just e2e` (starts the `dev`, `storage` and `demo` profiles, then `pnpm -C frontend e2e`), or `mise exec -- pnpm -C frontend e2e [spec]`. The proxy serves the SPA it was built with; after frontend changes run `just spa-refresh` (build and copy into the running proxy) or rebuild the proxy image. CI: [`e2e.yml`](../../.github/workflows/e2e.yml) ([ci-cd.md](../09-infrastructure/ci-cd.md) §e2e.yml).

**Independent and resettable.**
- `e2e/global-setup.ts` runs `demo:reset` once per run (about 35 s; `E2E_RESET=0` skips it, `E2E_RESET_COMMAND` replaces it), then signs Meera, Priya and Sam in through the form once and saves their storage state (`e2e/.auth/`, git-ignored). Specs use `test.use({ storageState: stateFor('meera') })`, so a run signs each user in at most twice (the theme spec signs Chen in through the form), well under the login throttle of 5 a minute per workspace and email. A context created inside a test inherits the file's storage state; a signed-out one passes `storageState: { cookies: [], origins: [] }`.
- Every record a spec creates carries a per-run stamp (`stamp()`), so specs never depend on each other or on a fresh reset, and run in parallel (4 workers locally, 2 in CI, 1 retry in CI).
- Set-up and assertions that the UI cannot show go through the API with the browser session (`sessionApi(context)`: cookie, XSRF header, app origin); the step under test always goes through the UI.
- webhook-echo has test-control endpoints (`tools/webhook-echo/echo.mjs`): `PUT /_echo/receivers/<name>` gives deliveries to `/hook/<name>` their own secret and a forced status, `GET /_echo/deliveries?path=` returns what it received and whether the signature verified. The suite reaches it on `127.0.0.1:9100`, the API's worker on `webhook-echo:9100`.
- Environment: `E2E_BASE_URL` (default `https://app.shp.localhost`), `E2E_API_URL`, `E2E_MAILPIT`, `E2E_WEBHOOK_ECHO`, `E2E_WEBHOOK_ECHO_INTERNAL`, `E2E_PASSWORD`; `E2E_AXE_REPORT=1` prints one line per scan.

**Scenarios** (as built 2026-09-22; times on the dev stack, 4 workers; the whole run is 41 tests in about 50 s plus the 35–38 s reset):

| Spec | Plan | What it proves | Time |
|---|---|---|---|
| `golden-path.spec.ts` | E2E-03…06 | create → automatic priority P2 with explanation → duplicate suggestion → automatic assignment → public reply → in progress → pending → resolved → closed; the timeline has the steps (Priya) | 13 s |
| `users.spec.ts` | M2-12 | an invitation stays *Invited* until the mailed link (Mailpit API) is accepted; the new Agent signs in without Settings | 5 s |
| `isolation.spec.ts` | E2E-08 | as Sam (Globex): an Acme ticket id → API 404 `application/problem+json` with the same code as an unknown id and no Acme data, not found by list search; the SPA shows the not-found state within 5 s (4xx are not retried); `/acme/tickets/{id}` stays in Globex; the Globex list does not show the ticket | 2 + 3 s |
| `theme.spec.ts` | E2E-09 | Dark on the login page survives a reload (read from the boot script before any app code: no flash) and signing in (Chen); Light survives a reload; System follows `prefers-color-scheme` live and after a reload | 4 s |
| `webhooks.spec.ts` | E2E-10 | Settings → Webhooks: add a subscription to webhook-echo, the secret shown once goes to the receiver; a new ticket → `ticket.created` delivery *Succeeded* 204 in the delivery log, verified by webhook-echo ("signature verified", body holds the ticket); receiver forced to 500 → a test delivery *Dead* 500 → receiver fixed → manual Retry → *Succeeded* 204, the same delivery id | 13 s |
| `api-clients.spec.ts` | E2E-11, M3-03 | Settings → API clients: create a client with four scopes, `client_credentials` token, `POST /v1/tickets` 201 `created_via=api`, replayed `Idempotency-Key` returns the same ticket, the ticket in the UI shows *api*; Revoke → the token and new token requests are 401; the audit log lists *API client created* and *API client revoked* for it | 11 s |
| `reports.spec.ts` | M3-20, M3-09, plan §intro | Backlog over time from the catalogue with its data table; CSV export → download from object storage, header + one line per row + totals; Ticket volume → drill-down from a count → a ticket → History → as of three days ago | 6 + 3 s |
| `accessibility.spec.ts` | E2E-01 axe | 16 screens × light/dark, see [accessibility.md](../06-design-system/accessibility.md) §Results | 32 × 2–4 s |

Not automated from the plan: E2E-02 (create contact; covered by component tests), E2E-07 (SLA breach via `demo:tick`, a manual demo step), the Mailpit check of E2E-04 (the invitation mail is checked instead).

## Permission matrix tests (`tests/Permissions`)

A table-driven test iterates `RouteMatrix` (route name × HTTP method × required permission) against the five default roles plus an API client with each scope. Expected outcome per cell is `200/201/204` (allowed), `403` (forbidden), or `skip` (record-level rule tested elsewhere). A separate route-list test asserts every registered `/api` route is either in the matrix or in the public allow-list (`auth/login`, `auth/accept-invitation`, `auth/forgot`, `oauth/token`, `up`, `health`, `docs/api*`). Adding a route without a matrix row fails CI.

## Algorithm test summary

| Algorithm | Unit tests (see algorithm page §Tests) | Scenario/experiment tests |
|---|---|---|
| Priority | scaled values at bounds, contributions sum to score, worked examples, thresholds, override precedence, settings validation | 12 scenarios; weight-change harness smoke; reproducibility hash |
| Assignment | each exclusion reason, lowest load wins, tie rules, round-robin rotation, determinism under shuffled input, empty set | workload replay smoke; Jain's index unit |
| Duplicates | word extraction, Jaccard at 0/1 and the worked example, threshold, ordering and limit, determinism | labelled pairs precision/recall at the default threshold (reported, no gate); cross-tenant candidates absent |
| SLA | transition table legality, pause/resume arithmetic, recompute from start, warning fraction, 24×7 and working-hours calendars, repeated checks notify once, policy selection | 10 timelines expected vs actual table |

**Contract suites.** `tests/Contracts/PriorityStrategyContract.php` and its siblings are classes with a static `register(string $label, Closure $factory)` method. It declares a Pest `describe` block that any implementation must pass (determinism, order independence, bounds, explanation shape with `strategy` and `strategy_version`, empty input). `tests/Contracts/BaselineStrategiesTest.php` registers the four baselines; the SLA factory receives the test's `Clock`. The `Contracts` directory is its own PHPUnit test suite. A replacement strategy adds one `register` line per contract and must pass before its binding is switched ([ADR-0023](../adr/0023-minimal-replaceable-algorithms.md)).

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
| End-to-end | Playwright | 41 | 41 | 0 | 0 | — | ≈ 50 s + reset (M3-12, local) |
| **Total** | | | | | | | |

Plus a sample-test-case table (ID, description, input, expected, actual, status) with ten representative cases per level, drawn from test names.

## Flakiness policy

A test that fails twice without a code change is quarantined the same day (`->skip('flaky: #issue')`), an issue is opened, and it must be fixed or deleted within two working days. Retries are allowed only in Playwright (one retry) and never in Pest/Vitest. Time-dependent tests must use `FrozenClock`; network-dependent tests are forbidden (MSW/Mailpit/webhook-echo only).
