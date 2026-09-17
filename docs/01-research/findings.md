# Research findings — summary

Consolidated conclusions from the ecosystem, tenancy, storage, infrastructure and algorithm research (2026-09-17). Each finding links to its evidence page and the ADR that acted on it.

## Findings that changed the brief's assumptions

| # | Finding | Effect | Evidence |
|---|---|---|---|
| F1 | **MinIO is archived and unmaintained** (last release 2025-10; console removed; AIStor is the paid successor); Laravel's docs now use RustFS. | Self-hosted store is RustFS (Garage alternative, BYO endpoint); code targets the generic `s3` disk. Needs confirmation. | [object-storage-options](object-storage-options.md), [ADR-0008](../adr/0008-object-storage.md) |
| F2 | **Laravel 13 / PHP 8.5** are current; Laravel 12 is past bug-fix support; Pest 5 and PHPUnit 13 need PHP ≥ 8.4. | Start on 13 / 8.5. | [backend-ecosystem](backend-ecosystem.md) |
| F3 | **`HasUuids` generates UUID v7** in Laravel 13; native `uuid` beats ULID text. | UUID v7 instead of ULID. | [ADR-0013](../adr/0013-identifiers.md) |
| F4 | **shadcn defaults to Base UI** since July 2026; Radix is optional; React Aria is a third base. | shadcn on Base UI, one primitive layer. | [frontend-ecosystem](frontend-ecosystem.md), [ADR-0002](../adr/0002-ui-primitive-strategy.md) |
| F5 | **TanStack Table v9** is GA (2026-08) but tutorials are v8; TanStack Form is adapter-free with Zod 4; no global store is needed. | Table v9 with v8 fallback; Form over RHF; Query + URL + context. | [ADR-0001](../adr/0001-frontend-architecture.md), [ADR-0003](../adr/0003-state-management.md) |
| F6 | **Passport should not authenticate the SPA**; password grant is discouraged. Sanctum is the documented SPA path. | Sanctum sessions; Passport client credentials only, time-boxed, Sanctum tokens fallback. | [ADR-0007](../adr/0007-authentication-and-oauth.md) |
| F7 | **stancl/tenancy v4 is unreleased**; v3 single-DB mode works but is the less-travelled path and its own docs warn of leak paths; v4 adds RLS for that reason. | Single-DB with RLS in the MVP and a mandatory isolation test suite. | [multitenancy-options](multitenancy-options.md), [ADR-0006](../adr/0006-multi-tenancy-model.md) |
| F8 | **Redis 8 is tri-licensed; Valkey 9 is BSD** and wire-compatible. | Valkey image everywhere. | [ADR-0014](../adr/0014-cache-queue-infrastructure.md) |
| F9 | **Terraform is BUSL under IBM; OpenTofu is MPL** and drop-in. Hetzner's provider lacks a bucket resource; DigitalOcean covers all five modules. | OpenTofu + DigitalOcean reference; Hetzner second. | [deployment-options](deployment-options.md), [ADR-0012](../adr/0012-deployment-architecture.md) |
| F10 | **Soketi is abandoned; Pusher cannot run on-prem**; Reverb is the self-hosted option; Echo has React hooks. | Polling first, Reverb as Should-have. | [realtime-options](realtime-options.md), [ADR-0009](../adr/0009-realtime-transport.md) |
| F11 | **ITIL 4 explicitly says impact × urgency evaluation "is not prioritization"**; the matrix belongs to vendors (Jira, ServiceNow, GLPI). | Our weighted, backlog-aware score is closer to ITIL 4 than a static matrix; cite accordingly. | [05-algorithms/priority-scoring](../05-algorithms/priority-scoring.md) |
| F12 | **Vendors disagree on SLA recomputation after priority change** (ServiceNow retroactive, Zammad from creation, Zendesk re-evaluate, osTicket transient flag) and Zendesk's reply timers do not pause in Pending. | `recompute_mode` and per-metric `pauses_on_pending` are configurable. | [05-algorithms/sla-evaluation](../05-algorithms/sla-evaluation.md) |
| F13 | **Duplicate bug-report literature (Runeson 2007 … Sun 2011 REP)** is the academic backbone; Runeson's Recall@10 ≈ 38 % sets realistic expectations. | Hybrid score framed as a simplified REP; Recall@K reported. | [05-algorithms/duplicate-detection](../05-algorithms/duplicate-detection.md) |
| F14 | **Vite 8 dropped Babel; TS 7 lacks a programmatic API until 7.1.** | React Compiler via Oxc; Biome instead of typescript-eslint. | [frontend-ecosystem](frontend-ecosystem.md) |

## Findings that confirmed the brief

Laravel modular monolith; PostgreSQL only; spatie/laravel-permission with teams; Horizon; Scramble for OpenAPI; Tailwind 4 token architecture; date-fns 4 with TZ; Recharts; Playwright; Docker Compose for all three targets; Ansible for hosts.

## Related systems (for the literature review)

Across osTicket, Zammad, GLPI, FreeScout, UVdesk, Znuny and OTOBO: only GLPI computes priority (static matrix); none does load- and skill-aware assignment with fairness measurement; none detects duplicates; SLA support ranges from a grace period (osTicket) to a paid module (FreeScout) to a three-timer calendar model (Zammad). The gap statement and per-system table are in [12-academic/algorithm-contribution.md](../12-academic/algorithm-contribution.md) and the algorithm pages.

## Caveats carried forward

RustFS 1.0 is days old (pin, keep Garage tested); Table v9 documentation gap; arXiv preprints cited as preprints; ITIL matrix must not be attributed to AXELOS; Zendesk's priority-change wording is inconsistent across two articles.
