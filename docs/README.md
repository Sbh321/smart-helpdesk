# Smart Helpdesk documentation

Planning and design documentation for **Smart Helpdesk**, a multi-tenant support-ticket management system with automated prioritisation, agent assignment, duplicate detection, SLA monitoring, integrations and analytics. Written 2026-09-17 as the research-and-planning deliverable; the build plan is in [../roadmap/](../roadmap/README.md).

## Reading order

1. [00-project/vision.md](00-project/vision.md) → [scope.md](00-project/scope.md) → [terminology.md](00-project/terminology.md) → [principles.md](00-project/principles.md)
2. [02-product/mvp-scope.md](02-product/mvp-scope.md) and the requirements
3. [03-architecture/overview.md](03-architecture/overview.md) then the ADRs in [adr/](adr/README.md)
4. Domain and algorithms: [04-domain/](04-domain/tickets.md), [05-algorithms/](05-algorithms/priority-scoring.md)
5. Everything else as needed by a roadmap task

## Sections

| Folder | Content |
|---|---|
| [00-project](00-project/vision.md) | vision, goals, scope, terminology, principles |
| [01-research](01-research/findings.md) | ecosystem research with sources, selected versions, findings summary |
| [02-product](02-product/mvp-scope.md) | personas, use cases, user flows, functional and non-functional requirements, MVP scope |
| [03-architecture](03-architecture/overview.md) | overview, frontend, backend, data, tenancy, security, storage, realtime, deployment, configuration, error handling, diagrams |
| [04-domain](04-domain/tickets.md) | tickets, contacts, agents and teams, SLA, notifications, integrations, audit, media, email, reporting |
| [05-algorithms](05-algorithms/priority-scoring.md) | priority scoring, agent assignment, duplicate detection, SLA evaluation, history reconstruction and time analytics, evaluation methodology |
| [06-design-system](06-design-system/tokens.md) | principles, tokens, themes, typography, spacing, components, page patterns, accessibility, UX review |
| [07-api](07-api/conventions.md) | conventions, authentication, pagination and filtering, errors, versioning, webhooks, documentation |
| [08-database](08-database/overview.md) | overview, entities, indexing, tenancy, migrations |
| [09-infrastructure](09-infrastructure/docker.md) | docker, local development, environments (hosts, services, credentials), production, terraform, ansible, backups, disaster recovery, CI/CD |
| [10-quality](10-quality/testing.md) | testing, code quality, security testing, performance testing, definition of done |
| [11-operations](11-operations/observability.md) | observability, queues, scheduler, logs, runbooks |
| [12-academic](12-academic/university-requirements.md) | university requirements (from the CACS452 guideline), algorithm contribution, result analysis plan, report mapping, report generation, demo plan |
| [adr](adr/README.md) | architecture decision records 0001–0022 |

## Conventions

- Documents state decisions; alternatives live in `01-research` and the ADRs.
- Terminology follows [00-project/terminology.md](00-project/terminology.md).
- A document that contradicts an ADR is a bug; fix the document or write a superseding ADR.
- No placeholder pages: a page exists only when it has content.
- This folder is also a site (M5-05): `cd docs && pnpm install && pnpm dev` (http://localhost:5180), `pnpm build` fails on a broken link. The sidebar follows the folder tree and each page's first heading; `README.md` is a folder's overview; links that leave `docs/` open the file on GitHub; `superpowers/` (agent working notes) is not published. Deployed at `platform-docs.<domain>` for signed-in platform super admins (M5-06).
