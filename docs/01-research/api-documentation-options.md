# API documentation options

Researched 2026-09-17. Decision in [ADR-0010](../adr/0010-api-documentation.md).

| Option | Version | Mechanism | Maintenance | Cost | Verdict |
|---|---|---|---|---|---|
| **dedoc/scramble** | 0.13.43 (2026-09-08) | Static analysis of routes, FormRequests, JsonResources and models → OpenAPI 3.1; built-in UI at a configurable path; `scramble:export` | 16 open issues, pushed 2026-09-16 | Free core (MIT); PRO ($99 solo) only adds spatie/data, spatie/query-builder, JSON:API inference we do not use | **Chosen** |
| knuckleswtf/scribe | 5.11.0 (2026-06) | Annotations/attributes plus "response calls" that execute endpoints; polished static HTML with try-it console and code samples | 103 open issues | Free | Rejected: response calls need tenant context and seeded data; annotation upkeep |
| darkaonline/l5-swagger | 11.1.0 | Manual `#[OA\...]` attributes + Swagger UI | 19 open issues | Free | Rejected: hours of annotation that rot |
| Hand-written OpenAPI YAML | — | Manual | — | Free | Rejected: drifts immediately |

## Scramble constraints to design around

- Introspects the live database for model attribute types, so `scramble:export` runs after `migrate` (CI job order).
- Resource fields resolve to `string` if the model cannot be inferred; keep `JsonResource` classes conventional and models resolvable.
- Only relationships in `$with` appear by default; exception status codes must be literal integers.
- 0.x versioning: pin `0.13.*`.
- Per-route PHPDoc/attribute overrides exist for the few endpoints inference gets wrong.

## Output pipeline

`/docs/api` (interactive UI, gated to authenticated tenant users and platform admins; public toggle per tenant later) → `/docs/api.json` → CI exports `openapi.json` → `openapi-typescript` generates `frontend/src/lib/api/schema.d.ts`, committed so contract changes are reviewable diffs.

## Sources

scramble.dedoc.co (docs, usage/response, pro); github.com/dedoc/scramble; packagist.org (dedoc/scramble, knuckleswtf/scribe, darkaonline/l5-swagger); scribe.knuckles.wtf.
