# Smart Helpdesk

[![backend](https://github.com/subham/smart-helpdesk/actions/workflows/backend.yml/badge.svg)](https://github.com/subham/smart-helpdesk/actions/workflows/backend.yml)
[![frontend](https://github.com/subham/smart-helpdesk/actions/workflows/frontend.yml/badge.svg)](https://github.com/subham/smart-helpdesk/actions/workflows/frontend.yml)
[![security](https://github.com/subham/smart-helpdesk/actions/workflows/security.yml/badge.svg)](https://github.com/subham/smart-helpdesk/actions/workflows/security.yml)

<!-- The badges use the repository slug assumed by the documentation; update them when the remote is created. -->

A multi-tenant support-ticket management system with automated prioritisation, agent assignment, duplicate detection, SLA monitoring, integrations and analytics. Final-year BCA project engineered as the foundation of a real SaaS/on-premise product.

**Status:** implementation in progress (milestone 1). Planning completed on 2026-09-17. The repository contains the platform documentation (`docs/`), the track-based roadmap (`roadmap/`), and the university and internship report sources (`report/`).

| Start here | |
|---|---|
| What and why | [docs/00-project/vision.md](docs/00-project/vision.md) |
| Hosts | `app`, `api`, `admin`, `monitor`, `docs`, `files`, `mail` under `shp.subhambhandari.com.np` ([ADR-0021](docs/adr/0021-host-layout-and-tenant-resolution.md)) |
| Scope boundary | [docs/02-product/mvp-scope.md](docs/02-product/mvp-scope.md) |
| Architecture | [docs/03-architecture/overview.md](docs/03-architecture/overview.md), [ADRs](docs/adr/README.md) |
| Algorithms | [docs/05-algorithms/](docs/05-algorithms/priority-scoring.md) |
| The plan | [roadmap/README.md](roadmap/README.md) → [roadmap/00-mvp-definition.md](roadmap/00-mvp-definition.md) |
| Schedule (settable) | [roadmap/schedule.yaml](roadmap/schedule.yaml), [roadmap/12-schedule.md](roadmap/12-schedule.md) |
| Open questions | [roadmap/08-decisions-open-questions.md](roadmap/08-decisions-open-questions.md) |
| University guideline | [CAPJ452-Project-III.pdf](CAPJ452-Project-III.pdf), [docs/12-academic/university-requirements.md](docs/12-academic/university-requirements.md) |
| Reports | [report/README.md](report/README.md) |

## Stack (decided)

Laravel 13 / PHP 8.5 API · React 19 / TypeScript 7 / Vite 8 SPA with TanStack Router, Query, Table, Form · Tailwind 4 + shadcn/ui on Base UI · PostgreSQL 18 · Valkey 9 · S3-compatible object storage (RustFS locally) · Stalwart mail server · Laravel Horizon · Docker Compose · Caddy · Ansible (any VM) · optional OpenTofu. Versions: [docs/01-research/versions.md](docs/01-research/versions.md).

## Quick start

```bash
git clone <repo> smart-helpdesk && cd smart-helpdesk
mise install          # Node, pnpm, just, lefthook, OpenTofu, Ansible
lefthook install
just setup            # secrets, containers, migrations, demo data, API types
open https://app.shp.localhost/acme   # API at https://api.shp.localhost, docs at https://docs.shp.localhost
```

Layout: `backend/` (Laravel), `frontend/` (Vite SPA), `infra/` (compose, caddy, tofu, ansible), `experiments/`, `docs/`, `roadmap/`, `report/`.
