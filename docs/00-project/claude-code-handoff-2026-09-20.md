# Claude Code handoff prompt — Smart Helpdesk, 2026-09-20

Paste the text below into a new Claude Code session in this repository. This is a status handoff, not a substitute for the authoritative roadmap and docs.

---

You are continuing Smart Helpdesk at `/home/subham/Projects/college/fyp/smart-helpdesk`. It is a Laravel 13 API, React 19 SPA and PostgreSQL 18 multi-tenant helpdesk MVP for a BCA final-year project and future SaaS. The working tree is intentionally very dirty with uncommitted work from several Milestone 2 sessions. Preserve all changes. Do **not** run `git commit` or push; the owner commits. Do not delete data, volumes or other projects' containers without asking.

First read `AGENTS.md`, then follow its reading order: `roadmap/README.md`, `roadmap/00-mvp-definition.md`, `python3 roadmap/tools/schedule.py --ready`, `roadmap/03-week-2-product.md`, the task block and linked docs, then the architecture/terminology pages. Do not reopen decisions already settled in `docs/`; if one is truly wrong, supersede it with an ADR. Never hand-edit dates in the schedule. Update task status and named docs in the same change.

## Owner's workflow direction

The owner wants all Week 2 features implemented first, with full tests and acceptance checks run **only at the end of Week 2 implementation** to move faster. This explicitly overrides any TDD/per-task test cadence. Do quick syntax, OpenAPI/type generation and focused static checks as useful, but do not report an untested task as Done. Keep it `[~]` until the Definition of Done and acceptance gate pass. No commits or pushes by coding agents. End each coding session with proposed Conventional Commit messages and the files each would cover.

The immediately preceding Codex turn was explicitly limited to finishing the immediate M2-10 integration and writing this handoff; it did **not** authorize starting M2-01 or other further tasks in that turn. In your new session, follow the owner's new instruction on what to do next.

## Accurate milestone status

- Milestone 1: 22/23 Done; M1-05 CI `[~]` because no GitHub remote/first green run yet. The M1 exit review lists the live `MVP-SHORTCUT`s and owner tasks.
- Milestone 2: `[~]`, **0/13 Done**. M2-02 through M2-08 and M2-10 are `[~]`; M2-01, M2-09, M2-11, M2-12 and M2-13 are `[ ]`. These counts are stated in `roadmap/03-week-2-product.md` and must change when statuses change. No M2 exit criterion has been claimed.
- The repo has ~185 modified/untracked paths, including prior-session Week 2 work. `git status --short` is the source of truth. Do not interpret untracked files as disposable.

## Work implemented in the session that produced this handoff

### M2-08 Media library and attachments (still `[~]`)

Implemented and applied three development DB migrations under `backend/app/Modules/Media/Database/Migrations/`: tenant-scoped Media folders/items/polymorphic links, quota columns/counters, system-folder backfill, and 5 GiB default-quota alignment. Added tenant registries, immutable tenant ID protection, composite FKs, change capture, model inventory and ForTenant factories. `mediables` uses a UUID row ID rather than the docs' old composite PK because the shared change-capture trigger requires a row key; `docs/04-domain/media.md` and `docs/08-database/entities.md` explain this. Provisioning seeds Tickets, Email and Branding folders for new workspaces.

Implemented Media intent/complete with presigned S3 PUT, 25 MiB and allowlisted MIME checks, OOXML ZIP validation, checksum/read-back/dimension verification, pending reservations and row-locked quota accounting; list, folder CRUD, rename/move/tag, trash/restore/purge, usage, download and variant routes; hourly cleanup; a queued `GenerateImageVariants` job using newly installed `intervention/image` 4.3 (dependency already recorded in `docs/01-research/versions.md`). Object keys passed to S3 are **relative** to the tenancy bootstrapper's `tenants/{tenant_id}/` prefix; this fixed an initial double-prefix bug. Added `quota_exceeded` problem code.

Added Ticket and Comment Media linking through `AttachMedia`, Ticket attachment routes, and linked Media in Comment responses. SPA has multi-file direct upload with progress/retry, picker, create/Comment/detail attachment integration, and Settings → Media library with folder tree, URL-bound search/filter, grid/list, tags, quota and actions. See `docs/superpowers/plans/2026-09-20-media-library-attachments.md`, `docs/04-domain/media.md`, `docs/03-architecture/storage.md`, and M2-08 progress for detail. The plan's checkboxes distinguish implementation from deferred acceptance and may be slightly stale: inspect code, not checkboxes, before continuing.

Potential gate issues to check: backend upload actions hard-code `s3`/`s3-presign` so the task's requested local-disk fake tests need an adapter or another approach; Media list resource does per-item tag/use queries (N+1); folder name race may bubble a unique-constraint error; retry, variant and quota invariants have not been tested. Do not claim these are failures without reproducing them.

### M2-10 Duplicate integration (still `[~]`)

Added and applied `backend/app/Modules/Tickets/Database/Migrations/2026_09_20_100000_create_ticket_duplicate_suggestions.php` to the development DB. It has tenant-scoped suggestion rows, composite ticket/decider FKs, uniqueness and checks, immutability and change capture. Added model, factory, Ticket relation and registry/inventory entries.

`DuplicateCandidates` queries up to 50 tenant-scoped, non-closed Tickets from the last 30 days, ordered by PostgreSQL title trigram similarity. `SuggestDuplicates` calls the configured `DuplicateStrategy` contract. `CreateTicket` persists scored suggestions, and `PersistDuplicateSuggestions` preserves previous accept/dismiss decisions on retries. Added `POST /tickets/preview-duplicates`, `GET /tickets/{ticket}/duplicates`, `POST /tickets/{ticket}/mark-duplicate`, and `POST /tickets/{ticket}/duplicates/{candidate}/dismiss`. All have permission middleware and resources. `MarkDuplicate` closes an open Ticket against a non-duplicate target, records an accepted suggestion/history, adds an internal note to the original, and emits lifecycle/status events. SLA close-as-duplicate cancels unfinished timers. See `docs/superpowers/plans/2026-09-20-duplicate-integration.md` and `docs/05-algorithms/duplicate-detection.md`.

This last turn regenerated `backend/openapi.json` via `docker compose exec -T app php artisan scramble:export --path=openapi.json -v` and `frontend/src/lib/api/schema.d.ts` via `mise exec -- pnpm api:types`; both commands exited 0 and the export showed no warnings. It added `frontend/src/features/tickets/api/duplicate-queries.ts`, `components/duplicate-preview-panel.tsx`, `components/ticket-duplicates-panel.tsx`, wired those into create and detail screens, added query keys and English copy, and updated `docs/07-api/conventions.md`, `docs/03-architecture/frontend.md` and M2-06/M2-10 progress. The create preview debounces 350 ms and skips short/stale inputs. The stored tab shows score and shared words, with permission-guarded dismiss and mark confirmation.

**M2-10 remains incomplete:** Settings → Automation → Duplicates threshold form needs the M2-01 tenant Settings service. `SuggestDuplicates` currently reads `config/helpdesk.php` defaults. The documented worked example, 5,000-row <300 ms preview, cross-tenant isolation, API, browser and axe checks are all deferred. `DuplicateSuggestionResource` exports `shared_words`, `strategy` and `strategy_version` as `unknown` in generated TS, so the UI defensively checks `Array.isArray` for words; tighten resource inference if useful. Revisit 409 problem-code consistency on dismissing accepted suggestions. Composite nullable FKs with `nullOnDelete()` appear in this and earlier Media/Contacts migrations; check deletion behavior at the gate because a composite `SET NULL` may try to null the non-null `tenant_id`.

## Other Week 2 work already in the dirty tree

M2-02 Agent/Team/Skill/Category and Shift backend/UI is broadly implemented; it previously passed 687 backend tests, 123 frontend unit and 95 browser, static checks and a proxy smoke, but still needs a live signed-in browser walkthrough. M2-03 has SLA policies/calendars, create/lifecycle/reply/priority hooks, sweep, UI and dev migration backfill; the last 734-test backend run was **before** later API/Media/Duplicate changes and is not current evidence. M2-04 has priority create/edit/ageing/override/preview, but tenant weights and organisation-change scoring remain. M2-05 has assignment candidate ranking, API/dialog/shortcuts, counters/reconciliation; race/reassignment/no-eligible notification and settings override remain. M2-06 has the Ticket create/detail/transition/edit/priority/assignment/SLA/Comments/Media/Duplicates integrations but no verified end-to-end golden path. M2-07 has Comment API/composer, SLA first reply, safe Markdown subset and queued public email; Mailpit and browser checks pending. Read each `Progress` bullet in `roadmap/03-week-2-product.md`; some older `Remaining` text predates newer cross-task integration, so reconcile before editing.

M2-01 Settings, M2-09 Notifications, M2-13 Reporting read models, M2-11 Ticket list UX and M2-12 Users/Roles UI have not been started. The critical chain includes M2-08 → M2-13/M3 reporting, and automation settings are needed by M2-03/04/05/10. Use `schedule.py --ready` and the milestone dependencies to select the next task, subject to the owner's instruction.

## Checks actually run in this session, and what is not verified

- Development migrations for Media and duplicate suggestions were applied using the app container's `DB_CONNECTION=pgsql_owner`; **not** migrated/tested against `helpdesk_test` in this session.
- Scramble export and typed-client generation succeeded after the M2-10 API changes. PHP syntax was checked for two Media classes earlier; `git diff --check` exited 0 at handoff.
- `php artisan route:list --path=duplicates` showed preview, list and dismiss; `--path=mark-duplicate` showed the mark route. This confirms registration only, not request behavior.
- A quick `mise exec -- pnpm typecheck` ran after the duplicate UI patch. It failed only on an SLA calendar `weekly_hours` tuple type in `features/sla/components/calendar-settings.tsx`, and stale generated route typing for Settings `calendars`, `media`, `priority` and `sla` routes (`routeTree.gen.ts` had not been regenerated). No duplicate-file TypeScript diagnostic appeared. This does **not** mean the frontend typechecks; rerun after fixing those items.
- A targeted Biome check on the touched duplicate UI files reported formatting and import-order issues, then `biome check --write` on those seven files exited 0. No full `pnpm lint` was run.
- No current Pest suite, Vitest unit/browser/axe, Pint, Larastan, frontend build, CI run, live upload or golden-path walkthrough was performed after these major changes, per the owner's implementation-first instruction. Never repeat the old passing counts as current verification.

## Rules and commands that matter

- Every new tenant table: non-null tenant FK, appropriate trait, `TenantTables`, `protectTenantId`, tenant-leading uniques, composite relation FKs, ForTenant factory and `tests/Support/TenantModelInventory.php`. Reportable tables also need `ReportableTables::TABLES` plus `captureChanges()` in their own migration. Do not iterate a registry that future migrations extend.
- Every `/v1` route needs `can:<permission>` or explicit route-protection allow-list. Check permissions, never role names. API shapes are JsonResources, then regenerate Scramble and typed client after API edits.
- Algorithm callers resolve `PriorityStrategy`, `AssignmentStrategy`, `DuplicateStrategy` or `SlaStrategy`, not baseline classes. Time comes from `Clock`. Keep `MVP-SHORTCUT` comments tied to real task/backlog numbers. Vocabulary is fixed in `docs/00-project/terminology.md`.
- Backend PHP only inside container; only one backend test process at a time because all tests share `helpdesk_test`. Use `docker compose exec -T app vendor/bin/pest`, `vendor/bin/pint`, and `vendor/bin/phpstan analyse --no-progress --memory-limit=1G` at the test gate. Do not change `phpunit.xml` forced server values or the TestCase `_test` database guard. Queue-worker tests need `--memory=2048`.
- Frontend from `frontend/`: `mise exec -- pnpm lint`, `typecheck`, `test`, `test:browser`, `build`, `tokens:check`. The proxy serves built SPA, so rebuild/restart the proxy to see SPA edits live. All UI copy belongs in `src/copy/en.ts`; kebab-case filenames, Base UI/shadcn primitive layer, no `components/` → `features/` imports.
- Dev stack: `docker compose up -d --wait`; smoke `infra/scripts/smoke.sh`; hosts are `app.shp.localhost`, `api.shp.localhost`, `docs.shp.localhost`, `mail.shp.localhost` on port 8081. Do not disturb other projects' containers. After API changes: `docker compose exec -T app php artisan scramble:export --path=openapi.json`, then `mise exec -- pnpm api:types` from `frontend/` and keep both generated files.

## Suggested next-session approach

Start by checking `git status --short`, reading the current M2 task blocks/docs and running the schedule. Do not mark any task Done merely because code exists. If the owner continues the implementation-first approach, M2-01 Settings is the most direct unblocker for tenant-configurable priority/assignment/duplicate thresholds and branding; M2-09 and M2-13 are other major gaps. Before the final Week 2 acceptance gate, reconcile all ongoing task progress bullets and the milestone count; then run migrations/tests/static checks and live golden paths, fix failures, update algorithm worked-example tables, and only then change `[~]` to `[x]` with a dated `Done` bullet (deviations first, evidence second). Never write dates into `schedule.yaml` manually.

At the end of your own session, report exactly what you changed and verified, what remains, and propose Conventional Commit messages grouped by files. Do not commit or push.
