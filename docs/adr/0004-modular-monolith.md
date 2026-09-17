# ADR-0004 Backend: Laravel modular monolith with actions and domain services

**Status:** Accepted (2026-09-17)

## Context

One developer, three weeks, but the code must grow into V1 with more modules (portal, channels, AI). Microservices and heavy layering are explicitly unwanted; Laravel should not be abstracted away.

## Decision

- **One Laravel 13 application** in `backend/`, PHP 8.5, API-only (`install:api`), no Blade UI except Horizon/Telescope/health pages.
- **Modules as PSR-4 namespaces** under `app/Modules/<Module>` with a service provider each, no module package: `Platform`, `Tenancy`, `Identity`, `Contacts`, `Agents`, `Tickets`, `Automation`, `Sla`, `Notifications`, `Integrations`, `Analytics`, `Audit`, plus `app/Support` for cross-cutting primitives (`Clock`, tenant-aware job trait, API response helpers).
- **Inside a module**: `Http/{Controllers,Requests,Resources,Middleware}`, `Models`, `Actions` (single-purpose write operations), `Domain` (pure PHP algorithms, enums, value objects, DTOs, exceptions), `Queries` (only for complex list/report reads), `Jobs`, `Events`, `Listeners`, `Policies`, `Notifications`, `Database/{Migrations,Factories,Seeders}`, `Routes/api.php`, `Console`.
- **Dependency rules**: `Tickets → Contacts, Agents`; `Automation → Tickets, Agents, Sla`; `Sla → Tickets`; `Notifications`, `Integrations`, `Analytics`, `Audit` react to events or read models and are never imported by domain modules. Cross-module writes go through the owning module's Action; cross-module reactions go through Laravel events. Cycles are forbidden (checked by a small architecture test).
- **Conventions**: thin controllers; FormRequests validate and authorise by permission; JsonResources define API shapes (also feed Scramble); Policies for record-level rules; Actions for writes (transaction + events); Domain services for algorithms as pure classes with explicit inputs/outputs and `explain()` output; Jobs implement the tenant-aware trait; backed Enums for statuses; readonly DTO classes; domain exceptions map to API error codes; scheduled commands per module registered in the module provider. **No repositories, no interfaces with one implementation, no event-bus abstraction.**

## Alternatives considered

- `nwidart/laravel-modules`: adds a package and its own conventions for what namespaces already give. Rejected.
- Hexagonal/Clean layering with ports and adapters: ceremony without a second adapter to justify it. Rejected.
- Flat default Laravel structure: fine for milestone 1, painful at 100 files per folder; modules cost nothing extra. Rejected.
- Microservices: operationally impossible for one developer and on-prem Compose. Rejected.

## Consequences

Each module can be explained on a whiteboard; algorithms are testable without HTTP or the database; the structure maps onto the report's module chapter. Slightly more boilerplate (one provider per module).

## Migration / future considerations

A module with heavy load (e.g., channels, AI) can be extracted later behind its existing Action/Event surface. Provider abstractions (storage, mail, realtime) remain Laravel's own driver system (ADR-0008, ADR-0009).
