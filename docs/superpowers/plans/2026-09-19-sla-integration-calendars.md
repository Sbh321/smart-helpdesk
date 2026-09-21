# SLA Integration and Calendars Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. The owner commits; agents must not commit or push.

**Goal:** Complete M2-03: tenant-safe SLA policies and calendars, persistent ticket timers, once-only evaluation, API and settings UI, with the roadmap's API timelines and performance acceptance checks.

**Architecture:** Keep `SlaStrategy` and `BusinessCalendar` pure. The Sla module owns persistence and policy selection, and consumes ticket lifecycle changes in the same transaction. Controllers expose resources, while the scheduler iterates tenants and locks eligible timer rows before evaluating them. The first-public-reply hook is an explicit integration point with M2-07's `AddComment`; M2-03 cannot claim its full API timeline acceptance until that endpoint exists.

**Tech Stack:** Laravel 13, PostgreSQL 18, Pest, React 19, TanStack Router/Query/Form, Vitest browser mode, Base UI via shadcn.

**Spec:** `roadmap/03-week-2-product.md` M2-03; `docs/04-domain/sla.md`; `docs/05-algorithms/sla-evaluation.md`; ADR-0020.

## Global Constraints

- Every new application-plane table has `tenant_id` with a tenant FK, an immutable-tenant trigger, tenant-leading uniqueness, a registry entry and model inventory classification. Relationship FKs are composite `(tenant_id, id)`.
- Every reportable table is registered in `ReportableTables` and gets its change-capture trigger in its own migration. Do not iterate future registry entries from earlier migrations.
- `/v1` routes have permission middleware; resources use `JsonResource`; errors use stable problem details. No role-name checks.
- Strategy callers type-hint `SlaStrategy`; time comes from `Clock`; the baseline remains pure and retains its contract/unit coverage.
- UI copy lives in `frontend/src/copy/en.ts`; components do not import features; new file names are kebab-case.
- Only one backend test process runs at once, in the `app` container. After API changes export `backend/openapi.json` and regenerate `frontend/src/lib/api/schema.d.ts`.
- Preserve the shared worktree's ongoing M2-02 and M2-06 edits. Do not commit, push or alter data/volumes destructively.

## Review Focus

- Cross-tenant policy/calendar/timer IDs must resolve to 404 or fail validation, never link across workspaces (Tasks 1, 4).
- A calendar edited after timer creation must not silently change an existing due time (Tasks 1, 2).
- A repeated or concurrent sweep must record one warning/breach and deliver one notification for each event (Task 3).
- A paused timer must not warn/breach until resumed; a priority change during pause must retain the open pause (Tasks 2, 3).
- A policy with no matching tier must fall back to the tenant default without reading another tenant's policy (Task 2).

---

### Task 1: Tenant-safe schema and calendar/policy models

**Files:** Create `backend/app/Modules/Sla/Database/Migrations/*`, `Models/{BusinessCalendar,CalendarHoliday,SlaPolicy,SlaTarget,TicketSlaTimer,SlaEvent}.php`, corresponding `backend/database/factories/*Factory.php`; modify `TenantTables.php`, `ReportableTables.php`, `tests/Support/TenantModelInventory.php`; test `backend/tests/Feature/Sla/SlaSchemaTest.php` and isolation suite.

**Interfaces:** Produces tenant-scoped Eloquent models and `TicketSlaTimer` rows with `kind`, `cycle`, `target_minutes`, policy/calendar references, strategy name/version and materialised deadlines.

- [ ] **Step 1: Write failing schema/isolation tests.** Assert `business_calendars`, `calendar_holidays`, `sla_policies`, `sla_targets`, `ticket_sla_timers`, `sla_events` exist; assert tenant-owned columns and `(tenant_id, ticket_id, kind, cycle)` uniqueness, tenant-safe foreign keys, immutable `tenant_id`, partial due/warning indexes and capture triggers. Test cross-tenant FK rejection and a second default policy rejection.
- [ ] **Step 2: Run red.** `docker compose exec -T app vendor/bin/pest tests/Feature/Sla/SlaSchemaTest.php tests/Isolation`
- [ ] **Step 3: Implement schema/models/factories.** Follow the Agents migration's explicit `TenantTables::protectTenantId()` and `ReportableTables::captureChanges()` pattern. Add registry entries before running the migration; create a unique `(tenant_id,id)` key on parents used by composite FKs. Use UUID v7 model keys and `ForTenant` factories. `sla_events` is append-only; preserve policy and calendar IDs on timers. Seed one default policy and four documented P1–P4 targets for newly provisioned tenants without duplicating rows on repeated provisioning.
- [ ] **Step 4: Run green.** Repeat focused tests, then `vendor/bin/pint --test` and `vendor/bin/phpstan analyse --no-progress --memory-limit=1G` in the app container.

### Task 2: Persistence adapter and ticket lifecycle

**Files:** Create `backend/app/Modules/Sla/Actions/{StartTicketSla,AdvanceTicketSla,RecomputeTicketSla}.php`, `Support/{SlaCalendarResolver,SlaPolicySelector,SlaTimerStore}.php`, event listeners as needed; modify `SlaServiceProvider.php`, `CreateTicket.php`, `TransitionTicket.php`; test `backend/tests/Feature/Sla/TicketSlaLifecycleTest.php`.

**Interfaces:** `StartTicketSla(Ticket): void`, `AdvanceTicketSla(Ticket, string $from, string $to): void`, `RecomputeTicketSla(Ticket): void`. The store maps `TimerData` to/from the timer row and appends `TimerOutcome` events; only the contract is injected into actions. Preserve atomicity with the ticket write.

- [ ] **Step 1: Write API-driven failing tests.** With `FrozenClock`, POST a P2 ticket and GET its SLA: two timers with the expected P2 warning/due instants and strategy metadata. Exercise pending/resume, resolve, reopen cycle 2, priority recomputation and organisation-tier policy selection. Assert editing a calendar does not alter stored deadlines and cross-tenant policy IDs cannot be used.
- [ ] **Step 2: Run red.** `docker compose exec -T app vendor/bin/pest tests/Feature/Sla/TicketSlaLifecycleTest.php`
- [ ] **Step 3: Implement contract binding, selector, calendar resolver and store.** Resolve `SlaStrategy` from `config('helpdesk.strategies.sla')` with configured warning fraction; use `TwentyFourSevenCalendar` for null calendar and `WorkingHoursCalendar` otherwise. Map each policy target's minutes to strategy seconds. Start inside `CreateTicket`; advance from ticket transition in the same transaction. Expose an idempotent `CompleteFirstResponse(Ticket)` action for M2-07's `AddComment`, then wire and API-test it when `AddComment` exists. Do not treat a resolution comment bypassing that action as a first public reply.
- [ ] **Step 4: Run green.** Focused suite plus ticket lifecycle tests; inspect the generated change history for the new rows.

### Task 3: Due sweep, heartbeat and once-only warnings/breaches

**Files:** Create `backend/app/Modules/Sla/Console/EvaluateSla.php`, SLA warning/breach events or notification dispatch adapter; modify `SlaServiceProvider.php`; test `backend/tests/Feature/Sla/SlaEvaluationCommandTest.php` and `backend/tests/Performance/SlaEvaluationTest.php`.

**Interfaces:** `sla:evaluate` iterates active tenants, selects only running/warning rows whose indexed threshold is due, locks rows, calls `SlaStrategy::check`, stores outcomes and emits events once. Schedule every minute with `onOneServer()->withoutOverlapping()`; expose a heartbeat to the existing health mechanism.

- [ ] **Step 1: Write failing tests.** Freeze time just before warning, at warning and at breach; run command twice at each instant and assert one `sla_events` row and one notification/event per transition. Assert paused rows are untouched, a concurrent/stale row is rechecked under lock and 10,000 not-yet-due rows do not make the sweep exceed the local 5 s target.
- [ ] **Step 2: Run red.** `docker compose exec -T app vendor/bin/pest tests/Feature/Sla/SlaEvaluationCommandTest.php`
- [ ] **Step 3: Implement the indexed query and per-row transaction.** Use `lockForUpdate` and re-read state/deadlines before calling `check`; record event and notification delivery in an idempotent path. Do not send email inside the row transaction. Follow the tenant-aware command pattern and health heartbeat convention already in the repo.
- [ ] **Step 4: Run green and benchmark.** Record actual elapsed time and environment; do not claim the 5 s acceptance target without measured output.

### Task 4: Policy, calendar and ticket SLA API

**Files:** Create `backend/app/Modules/Sla/Http/{Controllers,Requests,Resources}/*` and `Routes/api.php`; test `backend/tests/Feature/Sla/SlaApiTest.php` plus `tests/Permissions/RouteProtectionTest.php`.

**Interfaces:** `GET/POST/PATCH/DELETE /v1/calendars` and holidays, `GET/POST/PATCH/DELETE /v1/sla-policies`, `GET /v1/tickets/{ticket}/sla`. GET is guarded by the documented read permissions; mutations by `calendars.manage` or `sla.manage`. Responses are stable resource shapes.

- [ ] **Step 1: Write failing CRUD, permission and isolation tests.** Cover IANA zone validation, non-overlapping weekly windows, holiday recurrence, positive P1–P4 targets, 0.1–0.95 warning fraction, sole default, and 409 on deleting an in-use policy/calendar. Cross-tenant IDs return 404 or validation error, never mutate foreign rows.
- [ ] **Step 2: Run red.** `docker compose exec -T app vendor/bin/pest tests/Feature/Sla/SlaApiTest.php tests/Permissions/RouteProtectionTest.php`
- [ ] **Step 3: Implement FormRequests, controllers and JsonResources.** Keep business logic in actions. Include persisted explanation, current state, due/warning and remaining seconds in ticket SLA resource; do not recompute a timer on GET.
- [ ] **Step 4: Run green and regenerate contracts.** `php artisan scramble:export --path=openapi.json` in app, then `mise exec -- pnpm api:types` in `frontend/`; run drift checks.

### Task 5: Settings UI and ticket SLA panel

**Files:** Create `frontend/src/features/sla/{api,components}/*`, routes under `frontend/src/routes/$workspace/_app/settings/`; modify `frontend/src/copy/en.ts`, `frontend/src/features/tickets/components/ticket-screens.tsx`, `frontend/src/lib/api/query-keys.ts`; test browser cases in `frontend/src/features/sla/**/*.browser.test.tsx` and ticket detail browser tests.

**Interfaces:** Policy editor has P1–P4 response/resolution targets and calendar picker; calendar editor has zone, weekly windows and holidays; ticket panel shows persisted timer states, deadlines and a polite `aria-live` remaining-time label. Query keys remain workspace-scoped.

- [ ] **Step 1: Write failing browser tests with MSW.** Test create/edit validation, calendar holiday and multiple windows, policy selection, loading/empty/error states and an axe-clean ticket SLA panel. Verify remaining time updates without moving persisted due dates.
- [ ] **Step 2: Run red.** `mise exec -- pnpm test:browser` in `frontend/`.
- [ ] **Step 3: Implement feature routes, forms and panel.** Use existing Settings patterns, TanStack Form, typed API calls and `copy/en.ts`; only render the SLA panel when a ticket has data, with an explicit pre-integration state otherwise.
- [ ] **Step 4: Run green.** `pnpm lint`, `typecheck`, `test`, `test:browser`, `build`, `tokens:check`; rebuild proxy and run `infra/scripts/smoke.sh`. Run a signed-in live browser walkthrough if a browser is available; report honestly if unavailable.

### Task 6: Full acceptance, documentation and roadmap status

**Files:** Modify `docs/05-algorithms/sla-evaluation.md`, `docs/04-domain/sla.md`, `docs/08-database/{entities,indexing}.md` if implementation differs, `roadmap/03-week-2-product.md`; add M2-07 handoff note for first-public-reply integration if that task is still pending.

**Interfaces:** No new code; evidence-backed handoff.

- [ ] **Step 1: Run five 24×7 timelines and working-hours examples through API tests with frozen clock.** The first-public-reply case needs M2-07's `AddComment` API; until it lands, test the Sla action directly but keep M2-03 `[~]`. Include repeat notification check and measured 10k sweep result.
- [ ] **Step 2: Run full backend Pest (one process), Pint, Larastan; run all frontend checks and smoke.** Inspect `git diff --check` and generated contract drift.
- [ ] **Step 3: Update the algorithm page's pseudocode-to-method table and any documented deviations.** Mark M2-03 `[x]` and add `- **Done (date):**` with deviations first, then evidence only if all acceptance and Definition of Done checks pass; otherwise leave `[~]` with a precise progress note. Recalculate the milestone summary without adding hand-written schedule dates.
- [ ] **Step 4: Handoff without commit/push.** Suggest Conventional Commit message(s) and exact file scopes; note any verification gaps.
