# API versioning

## Scheme

- **URL major version**: `/v1`. The version is part of the contract, the OpenAPI document and the webhook envelope (`api_version: "v1"`).
- One major version is served at a time in the MVP; `v2` would be a parallel route group sharing modules, introduced only for breaking changes.
- The platform API (`/platform-api`) is versioned independently and is not part of the public contract.

## Compatibility policy

Additive changes ship without a version bump and are logged in `docs/07-api/CHANGELOG.md` (created with the first release):

| Non-breaking (allowed in v1) | Breaking (needs v2 or a deprecation cycle) |
|---|---|
| new endpoints, new optional request fields, new response fields, new enum values on **request** side, new `include`/filter/sort options, new webhook event types, new error codes for new situations | removing/renaming fields or endpoints, changing a field's type or format, tightening validation on existing fields, changing status codes or error codes of existing situations, new **required** request fields, changing pagination shape, removing enum values, changing webhook payload shape |

Clients must ignore unknown response fields and unknown webhook event types (stated in the developer docs).

New **response** enum values (for example a new ticket status) are treated as breaking for typed clients and are announced via the deprecation mechanism with a 90-day notice before appearing.

## Deprecation

Endpoints or fields being retired carry:

```http
Deprecation: @1767225600          # RFC 9745 epoch of when it became deprecated
Sunset: Sat, 01 Aug 2026 00:00:00 GMT
Link: <https://docs.smart-helpdesk.dev/api/changelog#…>; rel="deprecation"
```

Minimum 90 days between `Deprecation` and `Sunset`. Deprecated operations are marked `deprecated: true` in OpenAPI via a per-route attribute so Scramble renders them accordingly.

## Webhooks

Payload envelope carries `api_version`; `data` uses the same resource shapes as the REST API of that version. A subscription records the `api_version` it was created against; when `v2` exists, subscriptions keep receiving `v1` payloads until edited.

## OpenAPI document

`openapi.json` `info.version` follows `1.<minor>.<patch>`: minor for additive API changes, patch for documentation-only fixes. The exported document is committed with each backend change that alters routes, and CI fails if the export differs from the committed file, making contract changes reviewable. The frontend regenerates `schema.d.ts` from the committed document.

## Changelog

`docs/07-api/CHANGELOG.md` — one entry per release: version, date, added/changed/deprecated/removed, with links to the affected operations.
