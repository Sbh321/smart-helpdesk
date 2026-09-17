# MVP definition

## Statement

The MVP is a deployable, multi-tenant helpdesk in which a tenant administrator can configure teams, skills, categories, SLA policies and automation weights; agents can create and work tickets end to end; the system automatically scores priority, suggests duplicates, assigns agents and enforces SLA timers with explanations; managers see a dashboard; integrators can use a versioned, documented REST API with OAuth2 client credentials and signed webhooks; all of it runs with `docker compose up`, sends and receives email through a bundled mail server, reports on every entity over time with history and as-of views, keeps every file in a tenant media library, deploys with Ansible to any Linux VM (self-managed, VPS or any cloud, optionally provisioned by OpenTofu), is covered by tests including tenant isolation, and is accompanied by reproducible algorithm experiments and the documentation the university report needs.

Feature boundary (Must/Should/Could/Won't): [docs/02-product/mvp-scope.md](../docs/02-product/mvp-scope.md). Requirements: [functional](../docs/02-product/functional-requirements.md), [non-functional](../docs/02-product/non-functional-requirements.md).

## Calendar model

The plan is **effort-based and date-free**. Each task has a size (XS–XL) and a track (A backend/algorithms, B frontend, C infrastructure/mail/media/quality/academic). The working calendar — start date, working weekdays, hours per day, holidays, number of parallel tracks, size calibration and buffer — lives in [schedule.yaml](schedule.yaml), and `python3 roadmap/tools/schedule.py` turns it into dates. Details and the compression strategy: [12-schedule.md](12-schedule.md).

With the sprint defaults (every day, 16 h of development per day, four coding-agent tracks, AI-assisted calibration) the whole MVP, including the reporting module, computes to about **6.6 working days, 7.6 with the 15 % buffer**. Three tracks compute to about 9 days, and a strictly sequential run to about 29 days at 10 h/day; the cut list is how any variant is held to a deadline. The owner is the only human developer ([12-schedule.md](12-schedule.md) §Session protocol).

Buffer rule: if the tracks slip by more than half of the computed buffer before Milestone 3 starts, apply the cut list in order.

## Exit criteria per milestone

| Milestone | Exit criteria (all must hold) |
|---|---|
| M1 | algorithm and history cores unit-tested; change capture recording; `docker compose up` yields a working stack; platform admin creates a tenant; invited user logs in at `app.shp.localhost/acme`; contacts CRUD and a ticket created via UI appear in a server-paged table; isolation suite v1, route-protection test and CI are green; tokens/light-dark-system theme work |
| M2 | Full ticket lifecycle in UI with comments, attachments and history; priority, assignment, duplicate suggestion and SLA timers run on create with explanation panels; SLA sweep raises warnings/breaches; notifications in-app and via Mailpit; settings UIs for agents/teams/skills/categories/SLA/calendars/automation; media library with variants and quota; reporting read models rebuildable and verified |
| M3 | report catalogue, reports UI, entity 360 with as-of views, CSV/XLSX exports; dashboard; API clients with OAuth2 client credentials; webhooks with signed deliveries and retry UI; `/docs/api`; RLS enabled with tests; bundled mail server delivering DKIM-signed mail (inbound email-to-ticket if not cut); production Compose + Ansible deploy on a fresh VM from any provider; experiments E1–E4 produce result tables and plots; Playwright golden path + isolation + axe green; demo seed with reset/tick; 20-minute demo rehearsed twice |

## MVP done checklist

- [ ] All Must features in mvp-scope.md are `[x]` in the milestone files.
- [ ] CI green on `main` (backend, frontend, e2e, build).
- [ ] `just demo-reset && just e2e` passes on a clean checkout.
- [ ] Deployed once to a cloud or VPS VM (any provider) and once to a "fresh on-prem" VM (can be a local VM) via Ansible.
- [ ] `experiments/results/v1/` committed with tables T1–T11 and plots.
- [ ] Docs consistent with code: [06-documentation-plan.md](06-documentation-plan.md) review done; ADR index current.
- [ ] Demo script ([docs/12-academic/demo-plan.md](../docs/12-academic/demo-plan.md)) rehearsed offline.

## Cut order (apply top to bottom when buffer is gone)

1. Reverb realtime (polling remains) — M3-16
2. Saved reports (catalogue and exports stay) — part of M3-20
3. Inbound email-to-ticket (outbound mail stays) — M3-19
4. Agent shifts (calendars stay) — part of M2-02
5. Custom roles UI (API stays) — M3-17
6. Bulk actions in the ticket table — M2-11 (bulk part)
7. Password reset — M1-08 (reset part)
8. Idempotency-Key — part of M3-04
9. Audit log viewer UI (writes stay) — M3-03 (viewer part)
10. OpenTofu apply (keep Ansible on a manually created VM) — M3-14 (tofu part)
11. Performance sanity beyond the seeded list query — M3-11 (k6 part)
12. Hungarian batch mode / AHP module (Could) — never started unless idle

Never cut: tenant isolation tests, RLS, algorithm unit tests and experiments, change capture, the report catalogue and exports, API docs, demo seed, security review.
