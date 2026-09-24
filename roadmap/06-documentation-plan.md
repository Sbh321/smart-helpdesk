# Documentation plan

Documentation is produced inside tasks (Definition of Done criterion 7), not in a final sprint. This page lists what appears when, the maintenance rules, the university artefact schedule and the final review.

## Documents by week

| Week | Document | Purpose |
|---|---|---|
| M1 | `README.md` (root) | what the project is, quick start (`git clone`, `cp .env.example .env`, `just up`), links to docs and roadmap |
| M1 | `CONTRIBUTING.md` | branch/commit conventions, DoD, how to run tests, how to add a module |
| M1 | `backend/README.md`, `frontend/README.md` | per-app commands, layout, conventions pointers |
| M1 | `docs/09-infrastructure/local-development.md` updates | actual ports, hosts, profiles as implemented |
| M1 | `CLAUDE.md` / `AGENTS.md` | reading order for coding agents: roadmap task → context → conventions; vocabulary from [terminology](../docs/00-project/terminology.md) |
| M1 | ADR status check | any decision changed during scaffolding gets a new ADR or a note in [08-decisions-open-questions.md](08-decisions-open-questions.md) |
| M2 | Domain pages ([04-domain](../docs/04-domain/)) | updated to implemented fields, transitions, events |
| M2 | Algorithm pages ([05-algorithms](../docs/05-algorithms/)) | pseudocode aligned with code; worked examples regenerated |
| M2 | `docs/06-design-system/components.md` | list of implemented components and their tokens |
| M2 | API pages ([07-api](../docs/07-api/)) | conventions confirmed against real responses; error code catalogue |
| M3 | `/docs/api` (generated) and `backend/openapi.json` | interactive API reference; committed spec |
| M3 | `docs/07-api/webhooks.md` | final payloads, signature snippet, retry schedule |
| M3 | `docs/11-operations/runbooks.md` | deploy, rollback, backup/restore rehearsal notes, reset demo, rotate secrets, tenant suspend, failed-job retry |
| M3 | `docs/09-infrastructure/{terraform,ansible,production}.md` | as-built commands and variables |
| M3 | `experiments/README.md` | how to reproduce every table/plot; dataset versions; seeds |
| M3 | `docs/10-quality/test-report.md` | generated counts/coverage table |
| M3 | `docs/12-academic/*` updates | mapping verified; demo plan rehearsed |
| M3 | `LICENSE`, licence inventory | report appendix |

## Maintenance rules

1. A change that makes a page wrong is fixed in the same commit; the task file names the pages it owes.
2. No empty or placeholder files. A page exists only when it has content; sections that are not yet known say "decided in task Wx-yy" with a link, never "TBD".
3. ADRs are immutable after acceptance; changes produce a superseding ADR and an index update.
4. Enumerable lists (endpoints, permissions, events, env vars, queues, scheduled commands, compose services) are checked against code by `just docs-check` (a small script) from M2 onward; prose is reviewed by hand.
5. Diagrams are Mermaid in the page that owns them ([diagrams index](../docs/03-architecture/diagrams.md)); exported images are generated, never hand-edited.
6. Every `MVP-SHORTCUT` in code has a line in [09-v1-backlog.md](09-v1-backlog.md) §Cut from MVP.
7. The roadmap task file status (`todo → in-progress → done/deferred`) is the only progress tracker; no separate changelog until release.

## University report artefact schedule

| Artefact | Produced | Source | Stored |
|---|---|---|---|
| Use-case, ER, sequence, state, activity, DFD, deployment diagrams (SVG/PNG) | M3 day 5 | Mermaid pages via `just diagrams` (mermaid-cli) | `docs/exports/diagrams/` |
| Result tables T1–T11 (CSV → Markdown/LaTeX) | M3 day 3–4 | `experiments/results/v1/` | `docs/exports/tables/` |
| Plots 1–10 (SVG/PNG) | M3 day 4 | `experiments/plots.py` | `docs/exports/plots/` |
| Screenshots of the golden path (light and dark) | M3 day 5 | Playwright `page.screenshot` in a dedicated spec after `demo-reset` | `docs/exports/screenshots/` |
| Test report table | M3 day 5 | `just test-report` | `docs/10-quality/test-report.md` |
| Technology/version table | M3 day 5 | [versions.md](../docs/01-research/versions.md) re-verified against lockfiles | same page |
| Module/class listing | M3 day 5 | `just module-inventory` (lists Actions, Models, Events per module) | `docs/exports/module-inventory.md` |
| Chapter mapping | already | [report-mapping.md](../docs/12-academic/report-mapping.md) | |

## Pages expected to change during implementation

| Page | Expected change | Trigger |
|---|---|---|
| [03-architecture/backend.md](../docs/03-architecture/backend.md) | actual action/event names | M1–M2 |
| [04-domain/tickets.md](../docs/04-domain/tickets.md) | field types, transition guards refined | M2-06 |
| [05-algorithms/*](../docs/05-algorithms/) | defaults retuned after experiments; worked-example numbers | M2, M3-10 |
| [07-api/errors.md](../docs/07-api/errors.md) | final error-code catalogue | M2–M3 |
| [07-api/webhooks.md](../docs/07-api/webhooks.md) | final payload shapes | M3-05 |
| [08-database/entities.md](../docs/08-database/entities.md) | as-built columns; generated by `just schema-doc` if feasible | M2 end |
| [09-infrastructure/*](../docs/09-infrastructure/) | real compose service names, ports, variables, Ansible roles | M1, M3 |
| [11-operations/*](../docs/11-operations/) | real queue names, schedule, health checks | M2–M3 |
| [01-research/versions.md](../docs/01-research/versions.md) | any version bump before M1 day 1 | M1 day 1 |
| [roadmap/08-decisions-open-questions.md](08-decisions-open-questions.md) | decisions resolved, new questions | weekly |

Policy: when a page is updated, its first line keeps a `Last verified: <date>, task <id>` note added in M1.

## Final documentation review checklist (M3 day 5)

- [ ] Every ADR has status Accepted or Superseded; index matches files.
- [ ] `docs/README.md` index links resolve (`just docs-links` uses a Markdown link checker).
- [ ] No page contradicts an ADR (grep for MinIO, ULID, Radix, Pusher, Redis-only, X-Tenant header).
- [ ] Every Must-have in [mvp-scope.md](../docs/02-product/mvp-scope.md) has a done task and a page describing the built behaviour.
- [ ] Every FR-ID with class M appears in at least one test name or test dataset.
- [ ] Algorithm pages match code (worked examples pass snapshot tests).
- [ ] `experiments/README.md` reproduces tables/plots from a clean checkout.
- [ ] Runbooks executed once each (deploy, rollback, restore, demo reset).
- [ ] `versions.md` matches `composer.lock` / `pnpm-lock.yaml` / image tags.
- [ ] Terminology consistent (spot-check: tenant, contact, organisation, agent, timer, level).
- [ ] Report mapping table has an artefact path for every chapter section.
- [ ] Demo plan rehearsed twice; fallback recording exists.

## Reports (added 2026-09-17)

The Project III report is built from `report/` ([report-generation.md](../docs/12-academic/report-generation.md)). Chapters 1–3 are drafted now; each milestone updates the report sources it affects (M1: tools table; M2: module descriptions and unit-test tables; M3: system tests, result tables, screenshots, conclusion). The report is never generated from `docs/`, and `docs/` never copies report prose.

