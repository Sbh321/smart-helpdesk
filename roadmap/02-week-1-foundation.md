# Milestone 1 — Foundation

Goal: a running multi-tenant skeleton with authentication, permissions, design system, contacts and the ticket data model, all under CI. Exit criteria: [00-mvp-definition.md](00-mvp-definition.md). Status legend: `[ ]` todo · `[~]` doing · `[x]` done · `[-]` cut.

## Track plan

Work runs on four parallel tracks (see [12-schedule.md](12-schedule.md)): **A** backend and algorithms, **B** frontend, **C** infrastructure, mail/media plumbing, quality and academic artefacts, **D** pure algorithm and history cores, then reporting. Each track can be the owner or a coding agent in its own git worktree; the owner reviews and merges. Tasks carry a `Track` line; dates are computed from [schedule.yaml](schedule.yaml) by `python3 roadmap/tools/schedule.py`, never written by hand.

| Track | Order |
|---|---|
| A | M1-01 → M1-02 → M1-06 → M1-07 → M1-08 → M1-09 → M1-17 |
| B | M1-03 → M1-11 → M1-13 → M1-12 → M1-14 → M1-15 |
| C | M1-04 → M1-05 → M1-16 → M1-10 → M1-23 |
| D | M1-18 → M1-19 → M1-20 → M1-21 → M1-22 |

---

## Epic F1 — Repository and toolchain

### `[ ]` M1-01 Repository skeleton — S
**Feature:** monorepo layout and developer tooling.
- **Do:** create `backend/`, `frontend/`, `infra/{compose,caddy,tofu,ansible}`, `experiments/`, root `compose.yaml` (with `include`), `justfile` (targets from [local-development.md](../docs/09-infrastructure/local-development.md)), `.mise.toml` (php 8.5, node 24, pnpm 12, just, opentofu, ansible), `.editorconfig`, `.gitignore`, `README.md` (quick start), `CONTRIBUTING.md` (conventions summary linking docs), `lefthook.yml` (pint, biome, typecheck on staged), `CLAUDE.md`/`AGENTS.md` pointing to docs and roadmap reading order; `git init`, first commit.
- **Acceptance:** `mise install` succeeds; `just --list` shows targets; lefthook installed.
- **Depends:** —
- **Track:** A
- **Tests:** —
- **Docs:** README, CONTRIBUTING.

### `[ ]` M1-02 Backend scaffold — M
- **Do:** `laravel new backend --database=pgsql --pest --no-node`, `php artisan install:api`; `composer require` stancl/tenancy, spatie/laravel-permission, laravel/horizon, dedoc/scramble, league/flysystem-aws-s3-v3, spatie/laravel-health; dev: telescope, larastan, pint, boost; create `app/Modules/*` folders and service providers per [backend.md](../docs/03-architecture/backend.md); `app/Support` (Clock stub, TenantAwareJob stub); Pint config; `phpstan.neon` level 5; Pest arch test skeleton (module dependency rules, Domain ≠ Illuminate\Database); `config/helpdesk.php` defaults from [configuration.md](../docs/03-architecture/configuration.md); `.env.example` with all keys.
- **Acceptance:** `php artisan test` green with the arch test; `vendor/bin/pint --test` and `phpstan` clean; `php artisan route:list` shows `/v1/health` placeholder.
- **Depends:** M1-01
- **Track:** A
- **Tests:** arch test.
- **Docs:** backend README (module map, commands).

### `[ ]` M1-03 Frontend scaffold — M
- **Do:** Vite 8 + React 19 + TS 7 app; `@vitejs/plugin-react` with `compiler: true`; Tailwind 4 via `@tailwindcss/vite`; `shadcn init` (Base UI default, CSS variables); TanStack Router plugin with file routes; Query; Biome config (import boundary rules); Vitest (node + browser projects); Playwright config; `src/` layout from [frontend.md](../docs/03-architecture/frontend.md); `copy/en.ts`; `tsconfig` strict + `noUncheckedIndexedAccess`; `vite.config.ts` dev proxy to `https://app.shp.localhost/acme` (`/api`, `/sanctum`, `/docs`).
- **Acceptance:** `pnpm dev`, `pnpm build`, `pnpm lint`, `pnpm typecheck`, `pnpm test` all succeed with a hello route.
- **Depends:** M1-01
- **Track:** B
- **Tests:** one unit test (datetime helper).
- **Docs:** frontend README.

### `[ ]` M1-04 Docker Compose development stack — L, critical
- **Do:** backend Dockerfile (serversideup/php 8.5 fpm-nginx + extensions incl. `uv`, `postgresql-client`), frontend Dockerfile (Node build → Caddy image); `infra/compose/base.yaml` (app, horizon, scheduler, postgres 18 with init script creating `helpdesk_owner`/`helpdesk_app` roles and extensions, valkey, proxy), `dev.yaml` (mailpit, rustfs profile `storage`, telescope on, `develop.watch`, published ports), `demo.yaml` (webhook-echo); Caddyfile with the fixed host layout of [ADR-0021](../docs/adr/0021-host-layout-and-tenant-resolution.md) (`shp`, `app`, `api`, `admin`, `monitor`, `docs`, `files`, `mail` under `shp.localhost`, `tls internal`) and single-host mode; `config.json` templating at proxy start; healthchecks and `depends_on` conditions; `just setup/up/down/logs`.
- **Acceptance:** `just setup` on a clean machine brings the stack up in < 10 min; `https://api.shp.localhost/up` returns 200 and `https://app.shp.localhost/acme` loads in Chrome and Firefox; Mailpit and RustFS console reachable; a presigned PUT to RustFS from the browser origin succeeds (CORS); Horizon loads on `monitor.shp.localhost/horizon`.
- **Depends:** M1-02, M1-03
- **Track:** C
- **Tests:** smoke script `infra/scripts/smoke.sh` (curl checks) used by CI e2e later.
- **Docs:** [docker.md](../docs/09-infrastructure/docker.md), [local-development.md](../docs/09-infrastructure/local-development.md) updated with actual ports/names.
- **Fallback:** if `.localhost` TLS is troublesome on the host, run dev over plain HTTP with hosts-file entries and note the deviation.

### `[ ]` M1-05 CI basics — M
- **Do:** `backend.yml` (setup-php 8.5, composer cache, postgres 18 + valkey services, pint, larastan, pest with coverage artifact, `migrate:fresh` + `migrate:rollback` + `migrate`), `frontend.yml` (pnpm cache, biome ci, tsc, vitest, build), `build.yml` (images to GHCR on main), path filters plus the required-check workaround, gitleaks, `composer audit`/`pnpm audit`.
- **Acceptance:** both workflows green on the scaffold; badges in README.
- **Depends:** M1-04
- **Track:** C
- **Tests:** —
- **Docs:** [ci-cd.md](../docs/09-infrastructure/ci-cd.md) updated.

## Epic F2 — Tenancy and identity

### `[ ]` M1-06 Tenancy core — L, critical
- **Do:** `tenants`, `domains`, `tenant_counters`, `tenant_settings` migrations; `Tenant` model (no `HasDatabase`); stancl config for single-DB (cache, filesystem, queue, redis bootstrappers on; database off); custom `RlsTenancyBootstrapper` (ours) (`SET app.current_tenant`, `RESET` on end, `setPermissionsTeamId`); resolvers: `ResolveTenantFromPrincipal` (session tenant, API client tenant), pre-auth workspace resolver, single-tenant mode; reserved-slug validation; route groups bound to hosts: `platform` (`admin`, `/platform-api`) and `tenant-api` (`api`, `/v1`) with middleware order from [tenancy.md](../docs/03-architecture/tenancy.md); `EnsureTenantActive`; `BelongsToTenant` conventions documented in a module README; `TenantAwareJob` trait with `tags()`; `tenants:run` verified.
- **Acceptance:** an authenticated `api.shp.localhost/v1/ping` runs in the session's tenant; a session whose stored tenant differs from the user's → 401; unknown workspace at login → 401 `invalid_credentials`; suspended → 403; `TENANCY_SINGLE_TENANT` mode resolves for any host; a queued job dispatched in tenant context logs the tenant id in the worker; `SET app.current_tenant` visible via `SHOW` in a test.
- **Depends:** M1-04
- **Track:** A
- **Tests:** resolver tests (session, client, pre-auth workspace, single-tenant, platform host), reserved slugs, queue tenancy test, bootstrapper test.
- **Docs:** tenancy.md refinements; ADR-0006 unchanged.

### `[ ]` M1-07 Platform admin and tenant provisioning — M
- **Do:** `platform_users` + `platform` guard (session on the admin host); `POST/GET/PATCH /platform-api/tenants`, suspend/reactivate; `ProvisionTenant` action (tenant, domain, counters, default settings, default roles link, default categories, default SLA policy + targets, owner user + invitation); `platform:create-tenant` command; audit entries.
- **Acceptance:** creating a tenant via API produces all defaults idempotently; owner receives invitation in Mailpit.
- **Depends:** M1-06
- **Track:** A
- **Tests:** provisioning feature test asserting each default; idempotency; suspension middleware.
- **Docs:** [tenancy.md](../docs/03-architecture/tenancy.md) §Provisioning; API inventory rows.

### `[ ]` M1-08 SPA authentication and invitations — M, critical
- **Do:** Sanctum `statefulApi`, `SESSION_DOMAIN=.shp…`, stateful domain `app.shp…`, CORS with credentials on `api`, session `tenant_id`, `users` migration with `(tenant_id, email)` unique; endpoints `/sanctum/csrf-cookie`, `POST /v1/auth/login`, `POST /v1/auth/logout`, `GET /v1/me` (user, tenant, permissions, settings, unread count), `POST /v1/auth/accept-invitation` (signed), `POST /v1/auth/forgot-password`, `POST /v1/auth/reset-password` (Should); `EnsureTenantMembership`; throttles and lockout; audit of auth events.
- **Acceptance:** login works from the SPA origin; login with valid credentials but another workspace fails; lockout after 10 failures; invitation flow end to end via Mailpit link.
- **Depends:** M1-06
- **Track:** A
- **Tests:** feature tests for each endpoint incl. negative membership; throttle test.
- **Docs:** [07-api/authentication.md](../docs/07-api/authentication.md).

### `[ ]` M1-09 RBAC — M, critical
- **Do:** spatie migrations with `teams` and `tenant_id` key; permission catalogue seeder from ADR-0007; global default roles; `SetPermissionsTeam` middleware placement; `can:` on every route; route-protection test (every `/api` route has auth + permission except allow-list); permission matrix test harness (roles × routes table); `roles`/`permissions` read API and role CRUD API (custom roles); last-owner guard.
- **Acceptance:** matrix test passes for the default roles; a route without `can:` fails CI.
- **Depends:** M1-08
- **Track:** A
- **Tests:** route-protection, matrix harness, role CRUD, last-owner.
- **Docs:** [security.md](../docs/03-architecture/security.md) matrix kept in sync.

### `[ ]` M1-10 Tenancy isolation suite v1 — M
- **Do:** reflection test over models (primary/secondary/global lists), HTTP negative tests (cross-tenant IDs → 404, tampered session tenant → 401), schema assertions (tenant_id NOT NULL, unique includes tenant_id), queue context test, `actingAsTenantUser()` helper, factories seeding two tenants.
- **Acceptance:** suite runs in < 30 s; adding a model without a tenant trait fails.
- **Depends:** M1-06
- **Track:** C
- **Tests:** this task is tests.
- **Docs:** [10-quality/testing.md](../docs/10-quality/testing.md) §Isolation suite.

## Epic F3 — Design system and application shell

### `[ ]` M1-11 Tokens and themes — M
- **Do:** `styles/tokens.css` per [tokens.md](../docs/06-design-system/tokens.md) (primitive → semantic → `@theme inline`), `[data-theme]` + `@custom-variant dark`, density attribute, boot script in `index.html`, `ThemeProvider`/`useTheme`, `ThemeToggle`; contrast check script (node) for token pairs.
- **Acceptance:** no flash on reload in dark/system; contrast table passes 4.5:1; toggle persists.
- **Depends:** M1-03
- **Track:** B
- **Tests:** unit test for theme resolution; Playwright theme persistence (M3-12).
- **Docs:** tokens.md/themes.md refined with final values.

### `[ ]` M1-12 App shell, session and auth pages — L
- **Do:** `__root`, `_auth` (login, accept-invitation, reset), `_app` layout (Sidebar, Topbar, breadcrumbs, NotificationBell placeholder, CommandPalette shell), `SessionProvider` from `/me`, `useCan`, route guards (redirect with `redirect` param), `EmptyState`/`ErrorState`/`ForbiddenState`/`NotFoundState`, toasts (sonner), `PageHeader`, skip link and landmarks; `_platform` tenants list page (minimal).
- **Acceptance:** login → dashboard placeholder; permission-gated nav items; keyboard reachable; axe clean on login and shell.
- **Depends:** M1-08, M1-11, M1-13
- **Track:** B
- **Tests:** component test for `useCan` gating; browser test for login form errors.
- **Docs:** [components.md](../docs/06-design-system/components.md) inventory statuses.

### `[ ]` M1-13 OpenAPI and typed API client pipeline — M
- **Do:** Scramble config (`/docs/api` gated, `/docs/api.json`), `scramble:export` in CI producing `backend/openapi.json`; `pnpm api:types` (openapi-typescript) → `src/lib/api/schema.d.ts`; `lib/api/client.ts` (openapi-fetch, credentials include, XSRF header, problem-details parsing); `queryKeys.ts`; MSW handlers scaffold generated from schema for tests; CI drift check.
- **Acceptance:** `/me` and tenants endpoints documented and typed; a deliberate resource change fails the drift check.
- **Depends:** M1-02
- **Track:** B
- **Tests:** client unit tests (error mapping).
- **Docs:** [07-api/documentation.md](../docs/07-api/documentation.md).

### `[ ]` M1-14 DataTable on TanStack Table v9 — L, critical
- **Do:** `components/shared/DataTable` in server mode bound to Router search params (page, per_page, sort, filters), column visibility (localStorage), selection + bulk bar slot, sticky header, skeleton/empty/error states, keyboard row navigation, `FilterBar` primitives (multi-select, date range via react-day-picker, search input with debounce); `useListParams(schema)` hook.
- **Acceptance:** contacts list (M1-15) uses it with sort/page/filter round-tripping through the URL; back button restores state.
- **Depends:** M1-12
- **Track:** B
- **Tests:** browser tests: sort click updates URL; page change keeps filters; keyboard navigation.
- **Docs:** components.md DataTable section.
- **Time box:** one day; fallback `@tanstack/react-table@8` with identical component API.

## Epic F4 — Domain foundation

### `[ ]` M1-15 Contacts and organisations — L
- **Do:** migrations (organizations, contacts with `citext` email, tags, taggables), models with tenant traits, `pg_trgm` indexes, API CRUD + `typeahead` endpoint + list with filters, FormRequests with tenant-scoped uniqueness, resources, policies; UI: contacts list (DataTable), contact form (TanStack Form + Zod), organisation list/form, tag input (Combobox).
- **Acceptance:** CRUD via UI; duplicate email in tenant → 422; same email in another tenant allowed; typeahead returns fuzzy matches.
- **Depends:** M1-09, M1-14
- **Track:** B
- **Tests:** feature tests (CRUD, uniqueness, isolation), browser test for form validation.
- **Docs:** [contacts.md](../docs/04-domain/contacts.md) confirmed; API inventory.

### `[ ]` M1-16 Cross-cutting: errors, audit, clock, health, logs — M
- **Do:** `ProblemDetails` renderer + error code enum; `audit_logs` migration + `RecordAuditLog`; `Clock` binding (`SystemClock`, `FrozenClock` test helper); spatie/laravel-health checks (db, redis, storage, queue, scheduler heartbeat, disk) at `/v1/health` (token) and `/up`; Monolog JSON formatter + processors (request id, tenant id, user id); `X-Request-Id` middleware.
- **Acceptance:** a 422 and a 500 render problem details with `request_id`; health JSON lists checks; logs are JSON lines with ids.
- **Depends:** M1-02
- **Track:** C
- **Tests:** renderer tests; health test; audit action test.
- **Docs:** [error-handling.md](../docs/03-architecture/error-handling.md), [observability.md](../docs/11-operations/observability.md), [logs.md](../docs/11-operations/logs.md).

### `[ ]` M1-17 Ticket data model and list — L, critical
- **Do:** migrations (categories and a minimal `skills` table with the `category_skill` pivot — the full Agents module extends skills in M2-02; tickets, ticket_comments, ticket_events, ticket_assignments, `search_vector` stored generated column + GIN, trigram index on title, partial index on open tickets); `TicketStatus` enum with transition table; `Priority` enum; `Ticket` model + factories; `CreateTicket` action v0 (number allocation, `open`, history) without automation; `TicketListQuery` (filters, sort allow-list, FTS search, pagination); `GET/POST /v1/tickets`, `GET /tickets/{id}`, `GET /tickets/{id}/history`; UI: tickets list page on DataTable with status/priority badges, minimal create dialog (title, description, contact, category, impact/urgency).
- **Acceptance:** 10 000 seeded tickets page in < 300 ms locally; numbers gapless under a 20-parallel creation test; invalid transition → 422 `invalid_transition`; FTS search finds by word stem.
- **Depends:** M1-07, M1-09, M1-14, M1-16
- **Track:** A
- **Tests:** transition table unit tests; number concurrency test; list query tests (each filter, sort, search); isolation.
- **Docs:** [tickets.md](../docs/04-domain/tickets.md), [entities.md](../docs/08-database/entities.md) confirmed against migrations.

## Epic F5 — Algorithm and history cores (track D, pure PHP)

These classes are the minimal academic baselines behind replaceable contracts ([ADR-0023](../docs/adr/0023-minimal-replaceable-algorithms.md)). They have no database or HTTP dependencies ([backend.md](../docs/03-architecture/backend.md) §Conventions), so they start as soon as the backend scaffold exists and run in parallel with tenancy and UI work. Each task delivers the domain class, its input/output DTOs, the explanation output and 100 % line-covered unit tests; wiring into requests, jobs and the database happens in Milestone 2.

### `[ ]` M1-18 SLA baseline and calendars — M, critical
- **Do:** `Clock`/`FrozenClock`; `BusinessCalendar` with `TwentyFourSevenCalendar` and `WorkingHoursCalendar` (weekly hours, holidays, IANA zone); `SlaStrategy` contract and `SimpleSlaTimer` baseline over a `TimerData` value object (start, pause, resume, complete, recompute from start, check at an instant) returning events; `#[AcademicBaseline]` attribute; SLA contract test suite.
- **Acceptance:** the timelines and working-hours examples in [sla-evaluation.md](../docs/05-algorithms/sla-evaluation.md) pass as unit tests.
- **Depends:** M1-02
- **Track:** D
- **Tests:** ≈ 30 unit and contract tests.
- **Docs:** sla-evaluation.md examples confirmed.
### `[ ]` M1-19 Priority baseline — XS, critical
- **Do:** `PriorityStrategy` contract, `PriorityInput`/`PriorityResult`, `BasicWeightedPriority` (four scaled parts, weights, thresholds, explanation with strategy name and version), priority contract test suite.
- **Acceptance:** the worked-example table reproduces exactly.
- **Depends:** M1-02
- **Track:** D
- **Tests:** ≈ 15 unit and contract tests.
- **Docs:** priority-scoring.md table generated from the tests.
### `[ ]` M1-20 Assignment baseline — XS, critical
- **Do:** `AssignmentStrategy` contract, `TicketNeeds`/`AgentCandidate`/`AssignmentResult`, `LeastLoadedAgent` (eligibility rules with reasons, load ratio, tie rules, explanation), `FairnessIndex` (Jain), assignment contract test suite.
- **Acceptance:** the worked example reproduces; identical agents rotate like round robin; shuffled input gives the same result.
- **Depends:** M1-02
- **Track:** D
- **Tests:** ≈ 15 unit and contract tests.
- **Docs:** agent-assignment.md example confirmed.
### `[ ]` M1-21 Duplicate baseline and reply parser — S, critical
- **Do:** `DuplicateStrategy` contract, `TicketText`/`DuplicateResult`, `WordSet` (lowercase, split, stop-word file, minimum length, hyphenated codes kept), `JaccardDuplicates` (score, threshold, top five, shared words); `ReplyParser` (cut at the first quote marker or signature separator); duplicate contract test suite.
- **Acceptance:** the worked example gives 0.50 and 0.15; parser fixtures (Gmail, Outlook, Apple Mail) keep only the new text.
- **Depends:** M1-02
- **Track:** D
- **Tests:** ≈ 25 unit and contract tests.
- **Docs:** duplicate-detection.md example confirmed; email.md parser notes.
### `[ ]` M1-22 History and interval core — S
- **Do:** `ChangeReplayer` (backward and forward replay), `IntervalBuilder` (sweep, open interval, business durations via `BusinessCalendar`), `Percentile` helpers.
- **Acceptance:** replay property test and interval unit tests pass.
- **Depends:** M1-18
- **Track:** D
- **Tests:** ≈ 25 unit/property tests.
- **Docs:** history-and-time-analytics.md §3–4.

### `[ ]` M1-23 Change capture trigger — S, critical
- **Do:** `entity_changes` migration; `record_entity_change()` function; trigger attachment helper used by every later migration of a reportable table (applied to tables that exist now); `RlsTenancyBootstrapper` also sets `app.actor_type`, `app.actor_id`, `app.request_id`; jobs and scheduler set `system`; architecture test asserting every table in the reportable list has the trigger.
- **Acceptance:** creating, updating and deleting a contact writes three change rows with correct actor and diffs; excluded columns never appear; no-op updates write nothing.
- **Depends:** M1-06
- **Track:** C
- **Tests:** trigger tests on PostgreSQL; isolation (changes of tenant B invisible to A).
- **Docs:** [history-and-time-analytics.md](../docs/05-algorithms/history-and-time-analytics.md) §2, [entities.md](../docs/08-database/entities.md).

## Milestone 1 exit review

- Run the exit criteria; update statuses; record `MVP-SHORTCUT`s; write `docs/12-academic` notes on what screenshots/diagrams already exist; risk review.
