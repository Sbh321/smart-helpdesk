# Smart Helpdesk — guide for coding agents and new sessions

One developer steering coding agents on four parallel tracks, one-week sprint MVP, multi-tenant helpdesk (Laravel 13 API + React 19 SPA + PostgreSQL 18). Everything is decided in `docs/`; the plan is in `roadmap/`. Do not reopen decisions while implementing; write a superseding ADR if one is truly wrong.

## Read in this order at the start of a session

1. `roadmap/README.md` (working rules, task format, status legend)
2. `roadmap/00-mvp-definition.md` (exit criteria, cut order)
3. `python3 roadmap/tools/schedule.py --ready`, then the current milestone file (`roadmap/02-…`, `03-…`, `04-…`), the current task block and its **Track**; the schedule is in `roadmap/schedule.yaml` (`python3 roadmap/tools/schedule.py`)
4. The docs pages the task links to (architecture conventions in `docs/03-architecture/backend.md` and `frontend.md`; tenancy rules in `docs/03-architecture/tenancy.md`)
5. `docs/00-project/terminology.md` for vocabulary; `docs/10-quality/definition-of-done.md` before calling a task done

## Non-negotiables

- Tenant isolation: every application-plane table has `tenant_id`; use the tenant traits; never trust client-supplied tenant identifiers; add the model to the isolation test lists.
- Permissions, not roles, in code (`can:tickets.assign`).
- Algorithms are minimal academic baselines behind strategy contracts (`PriorityStrategy`, `AssignmentStrategy`, `DuplicateStrategy`, `SlaStrategy`); callers use the contract, never the baseline class; keep baselines as simple as their page in `docs/05-algorithms/` ([ADR-0023](docs/adr/0023-minimal-replaceable-algorithms.md)). Pure PHP, explanation output with strategy name and version, 100 % unit coverage, contract tests; time comes from `Clock`.
- No new dependency without a row in `docs/01-research/*` and a reason; one UI primitive layer (Base UI via shadcn).
- Errors are RFC 9457 problem details with stable codes; API shapes are `JsonResource`s so Scramble can infer them.
- UUID v7 keys; never expose auto-increment IDs.
- Tenant comes from the session or API client after login, never from the host or a header ([ADR-0021](docs/adr/0021-host-layout-and-tenant-resolution.md)). Hosts: `app`, `api`, `admin`, `monitor`, `docs`, `files`, `mail` under `shp.subhambhandari.com.np` (dev `shp.localhost`).
- Every new reportable table gets the change-capture trigger ([ADR-0022](docs/adr/0022-reporting-and-history.md)).
- Mark deliberate simplifications with `// MVP-SHORTCUT: <reason>; V1: <backlog item>`.
- Update the docs page named in the task in the same change; update the task status in the milestone file.
- The university report (`report/`) and the platform docs (`docs/`) are separate documents; never paste one into the other.

## Commands (once scaffolded)

`just setup | up | down | test | test-backend | test-frontend | e2e | lint | types | demo-reset | demo-tick | reproduce` — see `docs/09-infrastructure/local-development.md`.

## Vocabulary (never substitute)

Tenant · Workspace (UI name of a tenant) · Platform Super Admin · Media item · Business calendar · Shift · Inbound email · User · Role · Permission · Agent · Contact · Organisation · Ticket · Status (`open, assigned, in_progress, pending, resolved, closed`) · Priority (P1–P4) · Category · Skill · Team · Assignment · Comment (public reply / internal note) · SLA policy / timer · Webhook subscription / delivery · API client · MVP (never "prototype").
