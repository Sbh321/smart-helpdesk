# Testing plan (per milestone)

Reporting adds: trigger tests and the replay property test in M1 (M1-22, M1-23); read-model rebuild/verify and backlog-at-instant tests in M2 (M2-13); one feature test per catalogue report, permission filtering and export tests in M3 (M3-02, M3-09); and an E2E scenario "manager opens RPT-T02 backlog for last 30 days → drills into a day → opens a ticket's 360 page → views it as of three days ago" in M3-12. Host-layout tests (login from `app` to `api`, tampered session tenant, platform cookie rejected on `api`) run in M1.

Strategy and conventions: [docs/10-quality/testing.md](../docs/10-quality/testing.md). This page says *when* each test group is written and what must be green at each week's exit. Task IDs refer to the weekly roadmap files ([02](02-week-1-foundation.md), [03](03-week-2-product.md), [04](04-week-3-hardening-demo.md)).

## Principle

Tests are written inside the task that adds the behaviour, never as a separate "testing task" at the end. The only test-only tasks are the harness (M1), the isolation suite skeleton (M1), E2E scaffolding (M2 end) and the experiment reproducibility tests (M3).

## Milestone 1 — Foundation

| Day | Test work | Tied to |
|---|---|---|
| 1 | Pest installed with PostgreSQL service in CI; `TestCase` with `RefreshDatabase`; `FrozenClock` binding; `tests/Pest.php` helpers (`createTenant`, `actingAsTenantUser`, `tenantHost`); Larastan level 5; Pint | M1-02, M1-05 |
| 1 | Frontend: Vitest node config, Biome, `tsc` gate; first test for `lib/datetime` | M1-03 |
| 2 | Tenancy: resolver tests (subdomain, single-tenant mode, unknown host, central host), suspended tenant, bootstrapper sets `app.current_tenant` and permission team | M1-06 |
| 2 | Isolation suite skeleton: model reflection test (fails if a model is unlisted), schema assertion test (tenant_id NOT NULL, composite uniques) | M1-10 |
| 3 | Identity: login/logout/me, CSRF (419), throttle (429), invitation acceptance (signed, single-use, expired), password set; route-protection test with public allow-list | M1-08 |
| 3 | RBAC: default roles seeded; `can()` per role; `SetPermissionsTeam` before bindings; permission cache reset on tenant switch | M1-09 |
| 4 | Design system: ThemeProvider component test (system → dark via matchMedia mock, persistence, no flash boot script), token contrast script (`just tokens:check`) | M1-11 |
| 4 | Contacts: CRUD feature tests, tenant-scoped email uniqueness, archive refusal with tickets, typeahead scope | M1-15 |
| 5 | Ticket model: number allocation under concurrency (parallel creation test with 20 concurrent inserts), status enum transition table unit test, category/tag CRUD, `TicketListQuery` filters/sort determinism | M1-17 |
| 5 | CI: backend + frontend workflows green with path filters; coverage report uploaded | M1-05 |

**Exit:** ≈ 90 backend tests, ≈ 10 frontend tests; isolation items 1–4, 12 green; route protection green.

## Milestone 2 — Core product

| Day | Test work | Tied to |
|---|---|---|
| 1 | Ticket lifecycle API: every legal transition, every illegal transition → 422 `invalid_transition`, guards (resolution comment, reopen window), history rows | M2-06 |
| 1 | DataTable component test in browser mode: server mode paging/sort writes search params; MSW handlers from schema | M1-14 |
| 2 | Comments (public/internal visibility, first-response timestamp), attachments (intent/complete, MIME allow-list, size, mismatch, download 302, orphan cleanup), Markdown sanitiser XSS cases, isolation item 8 | M2-07, M2-08 |
| 2 | Agents/teams/skills CRUD; eligibility `AgentDirectory` query; counters | M2-02 |
| 3 | **SLA baseline tests** (transition table, pause/resume, recompute from start, warning fraction, repeated checks notify once, working-hours calendar) and the scenario tests with `FrozenClock` producing the expected/actual table; isolation item 11 | M2-03 |
| 3 | **Priority baseline tests** (scaled values, contributions sum, the worked examples, thresholds, override) + contract tests; hourly pass feature test | M2-04 |
| 4 | **Assignment baseline tests** (each exclusion reason, lowest load, tie rules, round-robin rotation, no eligible agent) + contract tests + concurrent-assign feature test (409) | M2-05 |
| 4 | **Duplicate baseline tests** (word extraction, Jaccard values, worked example, threshold, top five) + contract tests; preview endpoint; mark-as-duplicate; isolation item 10 | M2-10 |
| 5 | Notifications: channels per event, `tenant_id` on notifications (isolation item 9), queue tenant context (item 6), Mailpit assertion in E2E later | M2-09 |
| 5 | Dashboard query tests against seeded counts; permission matrix expanded to all M2 routes; Larastan level 6 | M3-01, M2-12 |
| 5 | Playwright scaffold: compose `demo` profile, login helper, first golden-path spec (login → create ticket) | M3-12 |

**Exit:** algorithm namespaces at 100 % line coverage; ≈ 330 backend tests; permission matrix covers all routes; one E2E spec green locally.

## Milestone 3 — Hardening and demo

| Day | Test work | Tied to |
|---|---|---|
| 1 | RLS: policy migration + schema tests (ENABLE/FORCE, role attributes), raw-query backstop test (isolation item 5); owner/app role split in CI | M3-07 |
| 1 | OAuth client credentials: token issue, scope enforcement, tenant binding, revocation, multi-guard routes | M3-04 |
| 2 | Webhooks: signature unit tests, replay window, URL validator (SSRF cases), delivery retry/backoff with fake HTTP, dead-letter after N, manual retry, disable after consecutive failures, isolation of delivery logs | M3-05 |
| 2 | Scramble export in CI without warnings; `schema.d.ts` diff check; rate-limit tests | M3-06 |
| 3 | Experiments: reproducibility hash tests for E1–E4; dataset README checks; performance seed and `EXPLAIN` checklist | M3-10, M3-11 |
| 3 | Exports/bulk actions/custom roles (Should-haves) feature tests as built | M3-09, M2-11, M3-17 |
| 4 | Full E2E suite (list below) + axe scans in CI `e2e` workflow; k6 run and results table | M3-12, M3-11 |
| 4 | Security manual review checklist; gitleaks; header curl checks | M3-08 |
| 5 | Demo seed acceptance checks (see [11-demo-dataset.md](11-demo-dataset.md)); `demo-reset` twice; test report generated for the university chapter | M3-13 |

**Exit:** all levels at target counts; CI `e2e` required on `main`; test report table filled; flaky list empty.

## End-to-end scenarios (Playwright)

Run against the `demo` profile after `just demo-reset`; each scenario is independent and logs in fresh.

| # | Scenario | Steps | Asserts |
|---|---|---|---|
| E2E-01 | Tenant admin login | open `app.shp.localhost/acme`, login as `meera@acme.test`, land on dashboard | topbar shows tenant name; `/me` permissions include `settings.manage`; axe clean |
| E2E-02 | Create contact | Contacts → New → name/email/org Globex Retail (premium) → save | row appears; duplicate email rejected inline |
| E2E-03 | Create ticket with automation | login as `arjun@acme.test` → New ticket "Cannot login after password reset (ERR-401)" category Technical, sev 3, imp 2, urg 3 → duplicate panel shows #1031 → proceed | ticket page shows priority P2 with breakdown table; assigned agent with shortlist; SLA panel with two timers running |
| E2E-04 | Agent responds | internal note → public reply | Mailpit API shows email to contact; first-response timer `met`; timeline has three entries |
| E2E-05 | Pending / resume / resolve | set pending → SLA badge "paused" → set in progress → resolve with comment | resolution timer `met`; `ticket.resolved` toast; status badge |
| E2E-06 | Close and reopen | close → reopen | status in_progress; new resolution cycle started |
| E2E-07 | SLA breach via demo-tick | as Priya, open near-breach ticket #1104 → run `just demo-tick 30m` (test endpoint) → reload | notification bell increments; badge "breached"; SLA event in the timeline |
| E2E-08 | Tenant isolation negative | login as `sam@globex.test` on `app.shp.localhost/globex`, paste Acme ticket URL and API URL | 404 page; API 404; no data leak in response body |
| E2E-09 | Theme persistence | toggle dark → reload → system → emulate `prefers-color-scheme: dark` | `data-theme` attribute correct after reload without flash (screenshot at first paint) |
| E2E-10 | Webhook delivery | Settings → Developer → subscription to `http://webhook-echo:8080/hook` → resolve a ticket → delivery log | delivery `succeeded` with 200; webhook-echo log shows "signature verified"; retry button works after echo returns 500 |
| E2E-11 | API client | create OAuth client → `POST /oauth/token` (request fixture) → `POST /v1/tickets` | 201; ticket visible in UI with `created_via = api` |

E2E-01…06 form the golden path used in the demo; E2E-07…11 are the academic proof points. Each runs under 60 s; total under 8 min in CI.

## CI setup timing

| When | What |
|---|---|
| M1 day 1 | backend workflow (Pint, Larastan, Pest with PostgreSQL + Valkey services) |
| M1 day 1 | frontend workflow (Biome, tsc, Vitest node, build) |
| M1 day 4 | Vitest browser mode job (Playwright Chromium in the runner image) |
| M1 day 5 | required checks on `main`; coverage artefacts |
| M2 day 5 | `e2e` workflow (compose up `--wait`, demo seed, Playwright), manual trigger only |
| M3 day 1 | `scramble:export` + `schema.d.ts` diff job; `migrate:rollback` job |
| M3 day 4 | `e2e` required on PRs to `main`; nightly schedule with k6 smoke |
| M3 day 4 | gitleaks and dependency-review jobs |

## Test data strategy

| Source | Used by | Properties |
|---|---|---|
| Model factories (`Database/Factories` per module) | unit, feature, component (via MSW fixtures generated from factories' JSON) | random but seeded per test via Pest `--seed`; tenant-aware |
| Demo seed (`DemoSeeder`, [11-demo-dataset.md](11-demo-dataset.md)) | E2E, manual QA, the demo | fixed seed, fixed identities, timers relative to now |
| Experiment datasets (`experiments/datasets/v1/*`) | algorithm evaluation, reproducibility tests | versioned, committed, never regenerated in place |
| Performance seed (`perf:seed`) | k6, `EXPLAIN` | bulk, 100k tickets, not committed |

Factories and the demo/experiment generators share the same title/description vocabulary module (`Database/Support/TicketCorpus.php`) so duplicate clusters exist in every dataset.

## Exit criteria summary

| Week | Backend tests | Frontend tests | E2E | Coverage | Static |
|---|---|---|---|---|---|
| M1 | ≥ 90 | ≥ 10 | 0 | reported | Larastan 5 |
| M2 | ≥ 330 | ≥ 35 | 1 local | algorithms 100 % | Larastan 6 |
| M3 | ≥ 480 | ≥ 50 | 11 in CI + axe | backend ≥ 70 % | Larastan 6, audits, gitleaks |
