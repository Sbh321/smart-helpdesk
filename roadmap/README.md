# Roadmap

The MVP plan for Smart Helpdesk, derived from the architecture and domain decisions in [docs/](../docs/README.md), not the other way round. Planning date: 2026-09-17; revised the same day into a compressed, track-based schedule. File names keep their original `week` wording for link stability; the content is organised as three milestones.

## How to read this folder

| File | Purpose |
|---|---|
| [00-mvp-definition.md](00-mvp-definition.md) | What "MVP done" means, calendar model, exit criteria, cut order |
| [schedule.yaml](schedule.yaml), [12-schedule.md](12-schedule.md), [tools/schedule.py](tools/schedule.py) | Settable working calendar (sprint profile by default), date calculator with critical chain and ready list, session protocol |
| [01-dependency-graph.md](01-dependency-graph.md) | Task dependency graph, critical path, parallelisable work |
| [02-week-1-foundation.md](02-week-1-foundation.md) | Milestone 1 tasks: repository, Compose, tenancy, auth/RBAC, design system, shell, contacts, ticket model |
| [03-week-2-product.md](03-week-2-product.md) | Milestone 2 tasks: settings, agents and shifts, SLA with calendars, priority, assignment, ticket UI, comments, media library, notifications, duplicates |
| [04-week-3-hardening-demo.md](04-week-3-hardening-demo.md) | Milestone 3 tasks: dashboard, API clients, webhooks, mail server, inbound email, deployment, RLS, experiments, performance, E2E, demo |
| [05-ui-revamp.md](05-ui-revamp.md) | Milestone 4 tasks: UX redesign of the built SPA (shell, ticket queue and detail, automation explainability, SLA, dashboard, reporting, record pages, developer platform, settings) |
| [05-testing-plan.md](05-testing-plan.md) | What is tested when; E2E scenarios; exit criteria |
| [06-documentation-plan.md](06-documentation-plan.md) | Documentation produced per milestone; report artefact schedule |
| [07-risk-register.md](07-risk-register.md) | Risks with probability, impact, mitigation, fallback, trigger; cut list |
| [08-decisions-open-questions.md](08-decisions-open-questions.md) | Decisions taken (with ADR links) and questions that need the owner's answer |
| [09-v1-backlog.md](09-v1-backlog.md) | Everything deliberately excluded from the MVP |
| [10-future-architecture.md](10-future-architecture.md) | How the architecture evolves after V1 |
| [11-demo-dataset.md](11-demo-dataset.md) | The deterministic demo dataset and reset/tick commands |

## Task format

Every task has an ID (`M1-04`), a size (XS/S/M/L/XL, converted to effort-days by `schedule.yaml`), a **Track** (A/B/C), a **Do** list, **Acceptance** criteria, **Depends** on other task IDs, **Tests** and **Docs** to update, and a `critical` marker when on the critical path. Tasks are complete only when they meet the [Definition of Done](../docs/10-quality/definition-of-done.md).

## Working rules

1. Work in sessions: `python3 roadmap/tools/schedule.py --ready` lists what can start; run one task per track in parallel worktrees; merge in dependency order.
2. When a task reaches its time box, apply its named fallback rather than pushing on.
3. Every few sessions (see [12-schedule.md](12-schedule.md) §Session protocol): risk review ([07-risk-register.md](07-risk-register.md)), update task statuses in the milestone files (`todo → doing → done → cut`), and move any cut item into the "cut during execution" section of [09-v1-backlog.md](09-v1-backlog.md).
4. `MVP-SHORTCUT` comments in code must point at a V1 backlog item.
5. The documentation is updated in the same task that changes behaviour ([06-documentation-plan.md](06-documentation-plan.md)).

## Status legend

`[ ]` todo · `[~]` doing · `[x]` done · `[-]` cut to V1. Statuses are edited in place in the milestone files.

## Command inventory

Artisan and `just` commands referenced by the operations pages (`demo:reset`, `demo:tick`, `sla:evaluate`, `tickets:reevaluate-priority`, `tenants:create|suspend|reactivate`, `tenancy:verify-isolation`, `storage:ensure-bucket`, `scheduler:heartbeat`, `webhooks:retry-due`, …) are created by the task that owns the behaviour; the authoritative lists are [docs/11-operations/scheduler.md](../docs/11-operations/scheduler.md) and [docs/11-operations/runbooks.md](../docs/11-operations/runbooks.md).
