# API errors

All non-2xx responses are RFC 9457 problem details with `Content-Type: application/problem+json` and a stable machine `code`. Rendering lives in one place (`App\Support\ProblemDetails`); architecture context in [03-architecture/error-handling.md](../03-architecture/error-handling.md).

## Shape

```json
{
  "type": "https://docs.smart-helpdesk.dev/errors/invalid_transition",
  "title": "Invalid status transition",
  "status": 422,
  "detail": "Ticket #1042 cannot move from closed to pending.",
  "code": "invalid_transition",
  "instance": "/v1/tickets/019a1f2e-7c3a-7d2b-9b1e-2f8a4e0c9c11/transition",
  "request_id": "01J8Z3KQ9X4N2M6P8R0T2V4W6Y",
  "errors": { "status": ["Allowed targets: in_progress"] },
  "meta": { "from": "closed", "to": "pending" }
}
```

| Field | Always | Notes |
|---|---|---|
| `type` | yes | URL of the code's documentation section |
| `title` | yes | short, human, stable per code |
| `status` | yes | HTTP status |
| `detail` | yes | specific, safe to show to users for 4xx; generic for 5xx |
| `code` | yes | `snake_case` identifier from the catalogue |
| `instance` | yes | request path |
| `request_id` | yes | correlates with logs |
| `errors` | 422 only | map of field → messages (dot paths for nested: `contact.email`) |
| `meta` | optional | structured extras (allowed transitions, retry-after seconds, limits) |

## Catalogue

| code | status | When | Client action |
|---|---|---|---|
| `bad_request` | 400 | malformed JSON, invalid UTF-8 | fix request |
| `unauthenticated` | 401 | no/invalid session or token, expired token | re-authenticate |
| `invalid_client` | 401 | `/oauth/token`: client unknown, wrong secret, revoked, or its workspace suspended. Also carries the RFC 6749 members `error: "invalid_client"` and `error_description`. A bearer request with a revoked or foreign token answers `unauthenticated` | check credentials |
| `invalid_scope` | 400 | `/oauth/token`: a requested scope does not exist or was not granted to the client (never silently dropped); `error: "invalid_scope"` | request only granted scopes |
| `unsupported_grant_type` | 400 | `/oauth/token`: any grant other than `client_credentials`; `error: "unsupported_grant_type"` | use client credentials |
| `forbidden` | 403 | missing permission, policy denies, scope insufficient, or an API client calling a route not open to clients | do not retry |
| `tenant_suspended` | 403 | tenant status ≠ active | contact platform |
| `account_locked` | 403 | too many failed logins | wait `meta.retry_after` |
| `not_found` | 404 | unknown route/resource, cross-tenant id | do not retry |
| `not_acceptable` | 406 | `Accept` not JSON | set header |
| `stale_update` | 409 | `version` mismatch (Should-have) | refetch and retry |
| `already_assigned` | 409 | concurrent assignment won by another request | refetch |
| `already_decided` | 409 | dismissing a duplicate suggestion that was already accepted | refetch |
| `in_use` | 409 | deleting or changing a record that others still reference; `meta` names the users | remove the references first |
| `last_owner` | 409 | removing/demoting the last Tenant Owner | assign another owner first |
| `has_dependents` | 409 | deleting a contact/category/team/skill in use | archive instead |
| `validation_failed` | 422 | FormRequest rules (see below) | fix fields |
| `invalid_transition` | 422 | state machine rejects the move; `meta.allowed` lists targets; `meta.reason` is `reopen_window_expired` or `closed_as_duplicate` when a reopen is refused | choose allowed target |
| `resolution_comment_required` | 422 | resolving without a resolution comment; `meta.field` is `comment` | show the comment box |
| `sla_target_missing` | 422 | the selected SLA policy has no target for the ticket's priority | fix the policy |
| `resolution_comment_required` | 422 | `resolved` without a comment | add comment |
| `no_eligible_agent` | 422 | auto-assign found nobody; `meta.exclusions` explains | assign manually |
| `duplicate_target_invalid` | 422 | target is itself a duplicate, is the same ticket, or is closed as duplicate | pick another target |
| `reopen_window_expired` | 422 | reopen after `reopen_window_days` | create a new ticket |
| `idempotency_key_reused` | 422 | same `Idempotency-Key` (same client, within 24 h), different body | use a new key |
| `attachment_invalid` | 422 | size/MIME mismatch at `complete` | re-upload |
| `settings_invalid` | 422 | weights do not sum to 1, thresholds not decreasing, unknown setting key; carries `errors` per field like `validation_failed` and `meta.section` | fix settings |
| `webhook_url_rejected` | 422 | not https, private range, unresolvable | change URL |
| `rate_limited` | 429 | throttle; `Retry-After` header and `meta.retry_after` | back off |
| `internal_error` | 500 | unexpected exception; detail hidden | report `request_id` |
| `storage_unavailable` | 502 | object storage error on intent/complete/download | retry later |
| `service_unavailable` | 503 | maintenance mode | retry after `Retry-After` |

Domain exceptions declare `code()` and `status()`; unknown exceptions map to `internal_error`. Codes are an enum (`App\Support\ErrorCode`) so a test can assert every code has a documented row here.

## Validation errors

```json
{
  "type": "https://docs.smart-helpdesk.dev/errors/validation_failed",
  "title": "The given data was invalid.",
  "status": 422,
  "detail": "3 fields failed validation.",
  "code": "validation_failed",
  "instance": "/v1/tickets",
  "request_id": "01J…",
  "errors": {
    "title": ["The title field is required."],
    "impact": ["The impact must be between 1 and 4."],
    "contact.email": ["The email has already been taken."]
  }
}
```

Messages are Laravel's translated strings; keys are input names exactly as sent, so the SPA maps them to form fields without transformation. Unknown query parameters on list endpoints produce `errors["filter.foo"]` / `errors["sort"]`.

## Examples

Cross-tenant access (user of Globex requests an Acme ticket id): `404 not_found` — never 403, to avoid confirming existence.

Rate limit:

```http
HTTP/1.1 429 Too Many Requests
Retry-After: 42
Content-Type: application/problem+json

{ "code": "rate_limited", "status": 429, "title": "Too many requests", "detail": "Login attempts are limited to 5 per minute.", "meta": { "retry_after": 42 }, … }
```

Unexpected error: `{ "code": "internal_error", "status": 500, "title": "Something went wrong", "detail": "The error has been logged with reference 01J8Z…", "request_id": "01J8Z…" }`.

## Frontend mapping

| status / code | Behaviour |
|---|---|
| 401 | clear session, navigate to login with `redirect` |
| 403 `forbidden` | `ForbiddenState` in place; no toast |
| 403 `tenant_suspended` | full-page notice |
| 404 | `NotFoundState`; for list item actions a toast "no longer exists" and list invalidation |
| 409 | alert with `detail`; `stale_update`/`already_assigned` refetch and re-open form |
| 422 `validation_failed` | field errors from `errors` |
| 422 other | form-level alert with `title`/`detail`; `invalid_transition` uses `meta.allowed` to rebuild the status menu |
| 429 | toast with countdown from `meta.retry_after` |
| 5xx / network | `ErrorState` with retry; toast includes `request_id` |

Copy for titles comes from `copy/en.ts` keyed by `code`, falling back to the server `title`.
