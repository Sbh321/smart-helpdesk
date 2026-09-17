# Error handling

## Backend

All API errors are **RFC 9457 problem details** (`application/problem+json`) with a stable machine `code`:

```json
{ "type": "https://docs.smart-helpdesk.dev/errors/invalid_transition", "title": "Invalid status transition", "status": 422, "detail": "Ticket #1042 cannot move from closed to pending.", "code": "invalid_transition", "instance": "/v1/tickets/019...", "request_id": "01J...", "errors": { "status": ["..."] } }
```

| Situation | Status | code | Source |
|---|---|---|---|
| Validation | 422 | `validation_failed` (+ `errors` map) | FormRequest |
| Domain rule | 409 or 422 | `invalid_transition`, `no_eligible_agent`, `duplicate_target_invalid`, `last_owner`, `stale_update` | `DomainException::code()` |
| Unauthenticated | 401 | `unauthenticated` | guard |
| Forbidden | 403 | `forbidden`, `tenant_suspended` | policy/middleware |
| Not found (incl. cross-tenant) | 404 | `not_found` | scope/binding |
| Rate limit | 429 | `rate_limited` (+ `Retry-After`) | throttle |
| Upstream (storage, mail) | 502/503 | `storage_unavailable`, `service_unavailable` | adapters |
| Unexpected | 500 | `internal_error` (detail hidden; `request_id` shown) | handler |

Exceptions are mapped in one renderer (`App\Support\ProblemDetails`); technical details go to logs with the same `request_id`. Jobs: failures retried per job config, then land in `failed_jobs` (Horizon) with tenant tag; webhook deliveries record their own state and never throw to the user.

## Frontend

| Layer | Behaviour |
|---|---|
| `lib/api/client.ts` | parses problem details into `ApiError`; network failures become `code: 'network'` |
| Forms | 422 `errors` mapped to fields; domain 409/422 shown as form-level alert with `title`/`detail` |
| Queries | per-component `ErrorState` with retry; global toast for unexpected errors with request id ("Something went wrong (ref 01J…)") |
| Auth | 401 → login with `redirect`; 403 → `ForbiddenState` (no data) |
| Realtime | disconnection indicator; polling continues |
| Retries | Query retries idempotent GETs twice with backoff; mutations never auto-retry |
| Copy | user-facing text from `copy/en.ts`; never raw server strings except `detail` for domain errors |
