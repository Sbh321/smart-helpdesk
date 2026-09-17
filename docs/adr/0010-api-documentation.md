# ADR-0010 API documentation: Scramble-generated OpenAPI 3.1

**Status:** Accepted (2026-09-17)

## Context

We need OpenAPI-compatible, interactive documentation at `/docs/api` and a machine-readable document for frontend type generation, without annotation upkeep. Research: [01-research/api-documentation-options.md](../01-research/api-documentation-options.md).

## Decision

`dedoc/scramble` (free core, pinned `0.13.*`) generates OpenAPI 3.1 from routes, FormRequests, JsonResources and models; UI served at `/docs/api` (authenticated), JSON at `/docs/api.json`; CI runs `migrate` then `scramble:export` and the frontend runs `openapi-typescript` on it, committing `schema.d.ts`. Manual per-route overrides only where inference fails. API conventions that keep inference accurate are in [07-api/conventions.md](../07-api/conventions.md).

## Alternatives considered

Scribe (response calls need tenant context; 103 open issues), l5-swagger (manual attributes), hand-written spec (drifts).

## Consequences

Docs are always current; resource classes must stay conventional; a 0.x dependency is pinned.

## Migration / future considerations

Public docs per tenant toggle; Scramble PRO if spatie/laravel-data is ever adopted; SDK generation (TypeScript/PHP) from the same document.
