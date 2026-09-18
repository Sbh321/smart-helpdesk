# Error handling

## Backend

All API errors are **RFC 9457 problem details** (`application/problem+json`) with a stable machine `code`:

```json
{ "type": "https://docs.shp.subhambhandari.com.np/errors/invalid_transition", "title": "Invalid status transition", "status": 422, "detail": "Ticket #1042 cannot move from closed to pending.", "code": "invalid_transition", "instance": "/v1/tickets/019...", "request_id": "01J...", "errors": { "status": ["..."] } }
```

| Situation | Status | code | Source |
|---|---|---|---|
| Malformed request | 400 | `bad_request` | HTTP exceptions |
| Validation | 422 | `validation_failed` (+ `errors` map) | FormRequest |
| Domain rule | 409 or 422 | `invalid_transition`, `no_eligible_agent`, `duplicate_target_invalid`, `last_owner` (422), `stale_update`, `conflict` (409) | `DomainException::code()` |
| Unauthenticated | 401 | `unauthenticated`, `invalid_credentials` | guard, login |
| CSRF token expired | 419 | `session_expired` | Sanctum/session |
| Wrong method | 405 | `method_not_allowed` | router |
| Upload too large | 413 | `payload_too_large` | media intent |
| Forbidden | 403 | `forbidden`, `tenant_suspended` | policy/middleware |
| Not found (incl. cross-tenant) | 404 | `not_found` | scope/binding |
| Rate limit | 429 | `rate_limited` (+ `Retry-After`) | throttle |
| Upstream (storage, mail) | 502/503 | `storage_unavailable`, `service_unavailable` | adapters |
| Unexpected | 500 | `internal_error` (detail hidden; `request_id` shown) | handler |

Exceptions are mapped in one renderer, `App\Support\Errors\ProblemDetails`, registered in `bootstrap/app.php`. Technical details go to logs with the same `request_id`.

Implementation rules (M1-16):

- The codes are the `App\Support\Errors\ErrorCode` enum. Each case has its status and title; existing values are never renamed.
- Business-rule failures extend `App\Support\Errors\DomainException`. The constructor takes the user-safe `detail` and optional `meta`, and the subclass returns its `code()`. The algorithm modules' own exceptions (`InvalidTimerTransition`, `InvalidStrategySettings`) are translated by the M2 actions that call them.
- The renderer applies to `/v1/*`, `/platform-api/*` and any request that expects JSON. It responds with `Content-Type: application/problem+json`.
- `type` is `https://<docs host>/errors/<code>`, and `instance` is the request path.
- `detail` is only filled for domain exceptions, validation, 419 and `abort(403, 'reason')`. Framework messages for 404 and 405 are dropped because they reveal routes.
- A 500 never includes the exception message. With `APP_DEBUG=true` a `debug` object adds the exception class and message.
- 429 responses keep `Retry-After`. Null members are omitted. Jobs: failures retried per job config, then land in `failed_jobs` (Horizon) with tenant tag; webhook deliveries record their own state and never throw to the user.

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
