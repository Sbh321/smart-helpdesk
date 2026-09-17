# API conventions

Applies to every endpoint under `/v1`. Decisions: [ADR-0004](../adr/0004-modular-monolith.md), [ADR-0007](../adr/0007-authentication-and-oauth.md), [ADR-0010](../adr/0010-api-documentation.md), [ADR-0013](../adr/0013-identifiers.md). Companion pages: [authentication.md](authentication.md), [pagination-filtering.md](pagination-filtering.md), [errors.md](errors.md), [versioning.md](versioning.md), [webhooks.md](webhooks.md), [documentation.md](documentation.md).

## Base URL and hosts

| Host | Purpose | Routes |
|---|---|---|
| `api.{PLATFORM_DOMAIN}` (single-host mode: `/api` on the one host) | tenant API | `/v1/*`, `/oauth/token`, `/sanctum/csrf-cookie`, `/broadcasting/auth` |
| `docs.{PLATFORM_DOMAIN}` | API reference | `/` (OpenAPI UI), `/openapi.json` |
| `admin.{PLATFORM_DOMAIN}` | platform (central) API | `/platform-api/*` |
| `monitor.{PLATFORM_DOMAIN}` | operations | `/horizon`, `/health`, `/log-viewer` |

Routes are bound to hosts, so `/v1` does not exist on `admin` and `/platform-api` does not exist on `api`. `PLATFORM_DOMAIN=shp.subhambhandari.com.np` in production. The tenant comes from the authenticated principal, never from the host or a header ([03-architecture/tenancy.md](../03-architecture/tenancy.md)).

## Resource naming

- Plural nouns, kebab-case path segments: `/tickets`, `/sla-policies`, `/api-clients`, `/audit-logs`.
- Nesting is at most one level deep and only for true children: `/tickets/{ticket}/comments`, `/webhooks/{webhook}/deliveries`. Children with their own lifecycle get top-level routes for item operations (`/media/{media}/download`, `/comments/{comment}`).
- State changes that are not plain field edits are **POST sub-resources** with a body, never `PATCH status=...`: `/tickets/{ticket}/transition`, `/tickets/{ticket}/assign`, `/tickets/{ticket}/auto-assign`, `/tickets/{ticket}/priority`, `/tickets/{ticket}/mark-duplicate`, `/webhooks/{webhook}/test`, `/webhook-deliveries/{delivery}/retry`. This keeps the state machine guarded in one Action and documents each transition as its own operation in OpenAPI.
- Route parameters are UUID v7 strings; ticket `number` is accepted as an alternative lookup only on `GET /tickets/by-number/{number}`.

## JSON shapes

| Rule | Detail |
|---|---|
| Field names | `snake_case` in requests and responses (matches Eloquent and Scramble inference; the frontend keeps snake_case types instead of remapping) |
| Identifiers | `id` is a UUID v7 string; foreign keys are `<relation>_id`; embedded relations appear under the relation name when requested with `include` |
| Timestamps | ISO-8601 UTC with `Z` (`2026-09-17T09:40:00Z`); durations are integer seconds (`paused_total_seconds`) or minutes where the domain says so (`first_response_minutes`) |
| Enums | lowercase strings exactly as stored: `open`, `in_progress`, `P1`, `internal` |
| Booleans / nulls | JSON `true`/`false`/`null`; absent optional fields on write mean "unchanged" (PATCH) |
| Money | none in the MVP |
| Text | plain UTF-8; ticket `description` and comment `body` accept limited Markdown, returned as stored (rendering and sanitising happen in the client) |
| Explanations | JSON objects as produced by the algorithms (`priority_explanation`, `assignment.explanation`, `duplicate.breakdown`) |

## Response envelope

Laravel's default `JsonResource` wrapper is used **consistently** for every success response:

```json
{ "data": { "id": "019...", "number": 1042, "title": "..." } }
```

```json
{ "data": [ ... ], "meta": { "current_page": 2, "per_page": 25, "total": 1387, "last_page": 56 }, "links": { "next": "...", "prev": "..." } }
```

Reasons: one shape for singles and collections; Scramble infers it without configuration; pagination `meta`/`links` need a sibling of `data` anyway; no custom wrapper class to maintain. Errors never use `data`; they are problem-details documents ([errors.md](errors.md)). `204 No Content` has no body.

## Headers

| Header | Direction | Behaviour |
|---|---|---|
| `X-Request-Id` | in/out | Accepted if the client sends a valid UUID-like token (≤ 64 chars, `[A-Za-z0-9_-]`); otherwise generated. Echoed on every response, included in logs and in problem details as `request_id`. |
| `Idempotency-Key` | in | Honoured on `POST /tickets`, `POST /tickets/{ticket}/comments`, `POST /contacts` for **client-credentials** requests. Key + client id + route are stored in `idempotency_keys` for 24 h with the response; a replay returns the stored response with `Idempotent-Replayed: true`; a different body with the same key → `422 idempotency_key_reused`. |
| `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `Retry-After` | out | Laravel throttle headers; `Retry-After` on 429 |
| `Deprecation`, `Sunset` | out | see [versioning.md](versioning.md) |
| `Accept` | in | `application/json` (default); anything else → 406 `not_acceptable` |
| `Content-Type` | in | `application/json` for bodies; multipart is not used (uploads go directly to storage) |

## Query parameters

`page`, `per_page`, `sort`, `filter[...]`, `search`, `include`, `cursor` as defined in [pagination-filtering.md](pagination-filtering.md). Sparse fieldsets (`fields[...]`) are **not** supported in the MVP: the resources are small and Scramble cannot type a dynamic shape. `include` is an allow-list per endpoint (`include=contact,organization,assignee,team,category,tags`); unknown includes → 422.

## HTTP status usage

| Status | Used for |
|---|---|
| 200 | successful GET, PATCH, POST sub-resource actions returning the resource |
| 201 | resource created (`Location` header set) |
| 202 | accepted for async work (exports, webhook test delivery) with a job/resource id |
| 204 | successful DELETE or action with nothing to return (logout, mark read) |
| 302 | attachment download redirect to a presigned URL |
| 400 | malformed JSON / unparsable request |
| 401 | unauthenticated, invalid credentials, or a session whose user no longer matches its tenant |
| 403 | forbidden by permission or policy; tenant suspended |
| 404 | not found, including cross-tenant IDs |
| 406 | unacceptable `Accept` |
| 409 | conflict with current state (`stale_update`, `already_assigned`, `last_owner`) |
| 422 | validation and domain rule violations |
| 429 | rate limited |
| 500 / 502 / 503 | unexpected / upstream / maintenance |

## Rate limits

| Scope | Limit |
|---|---|
| Login, password reset | 5 / min per email + IP |
| Session API | 300 / min per user |
| Client-credentials API | 120 / min per client (configurable per client) |
| Upload intents | 30 / min per user |
| Duplicate preview | 30 / min per user |
| Webhook test delivery | 10 / min per tenant |

Keys are `rl:{tenant}:{user|client}:{group}` ([03-architecture/tenancy.md](../03-architecture/tenancy.md)).

## Endpoint inventory (MVP)

Guards: **S** = Sanctum session (SPA users), **C** = Passport client credentials (API clients), **P** = platform admin guard, **–** = public. Permission column lists what the guard must hold; client scopes carry the same names with `:` instead of `.` ([authentication.md](authentication.md)).

### Auth and session

| Method | Path | Guard | Permission | Notes |
|---|---|---|---|---|
| GET | `/sanctum/csrf-cookie` | – | — | sets `XSRF-TOKEN` |
| POST | `/v1/auth/login` | – | — | throttled |
| POST | `/v1/auth/logout` | S | — | 204 |
| GET | `/v1/me` | S | — | user, tenant, permissions, settings, unread count |
| PATCH | `/v1/me/preferences` | S | — | theme, density |
| POST | `/v1/auth/invitations/{token}/accept` | – (signed) | — | sets password |
| POST | `/v1/auth/password/forgot` | – | — | throttled |
| POST | `/v1/auth/password/reset` | – (signed) | — | |
| POST | `/oauth/token` | – | — | `grant_type=client_credentials` only |

### Platform (central host)

| Method | Path | Guard | Notes |
|---|---|---|---|
| GET/POST | `/platform-api/tenants` | P | list / provision |
| GET/PATCH | `/platform-api/tenants/{tenant}` | P | |
| POST | `/platform-api/tenants/{tenant}/suspend`, `/reactivate` | P | |
| GET | `/platform-api/audit-logs` | P | platform-level entries |
| GET | `/health`, `/up` | – | `/health` gated to platform admins for details |

### Identity

| Method | Path | Guard | Permission |
|---|---|---|---|
| GET | `/v1/users` | S | `users.manage` (or `agents.view` for the agent subset) |
| POST | `/v1/users/invitations` | S | `users.manage` |
| PATCH | `/v1/users/{user}` | S | `users.manage` |
| POST | `/v1/users/{user}/disable`, `/enable` | S | `users.manage` |
| GET/POST | `/v1/roles` | S | `roles.manage` (GET also `users.manage`) |
| GET/PATCH/DELETE | `/v1/roles/{role}` | S | `roles.manage` |
| GET | `/v1/permissions` | S | `roles.manage` |

### Contacts

| Method | Path | Guard | Permission |
|---|---|---|---|
| GET | `/v1/contacts` | S, C | `contacts.view` |
| POST | `/v1/contacts` | S, C | `contacts.manage` (idempotent for C) |
| GET | `/v1/contacts/{contact}` | S, C | `contacts.view` (`include=organization,tags`) |
| PATCH | `/v1/contacts/{contact}` | S, C | `contacts.manage` |
| POST | `/v1/contacts/{contact}/archive` | S | `contacts.manage` |
| GET | `/v1/contacts/{contact}/tickets` | S | `tickets.view` (Could-have) |
| GET/POST | `/v1/organizations` | S, C | `contacts.view` / `contacts.manage` |
| GET/PATCH | `/v1/organizations/{organization}` | S, C | `contacts.view` / `contacts.manage` |
| GET/POST | `/v1/tags` | S, C | `tickets.view` / `tickets.update` |
| DELETE | `/v1/tags/{tag}` | S | `settings.manage` |

### Agents, teams, skills, categories

| Method | Path | Guard | Permission |
|---|---|---|---|
| GET/POST | `/v1/categories` | S, C (GET) | `tickets.view` / `settings.manage` |
| PATCH/DELETE | `/v1/categories/{category}` | S | `settings.manage` |
| GET/POST | `/v1/skills` | S | `agents.view` / `agents.manage` |
| PATCH/DELETE | `/v1/skills/{skill}` | S | `agents.manage` |
| GET/POST | `/v1/teams` | S, C (GET) | `agents.view` / `teams.manage` |
| GET/PATCH/DELETE | `/v1/teams/{team}` | S | `teams.manage` |
| PUT | `/v1/teams/{team}/members` | S | `teams.manage` |
| GET | `/v1/agents` | S, C | `agents.view` (`include=skills,teams`) |
| GET/PATCH | `/v1/agents/{agent}` | S | `agents.view` / `agents.manage` (availability: self or manager) |
| PUT | `/v1/agents/{agent}/skills` | S | `agents.manage` |
| GET | `/v1/agents/{agent}/workload` | S | `agents.view` (Should-have) |

### Tickets

| Method | Path | Guard | Permission | Notes |
|---|---|---|---|---|
| GET | `/v1/tickets` | S, C | `tickets.view` | list query, see [pagination-filtering.md](pagination-filtering.md) |
| POST | `/v1/tickets` | S, C | `tickets.create` | runs priority, duplicates, SLA, assignment; idempotent for C |
| POST | `/v1/tickets/preview-duplicates` | S | `tickets.create` | no persistence |
| GET | `/v1/tickets/{ticket}` | S, C | `tickets.view` | `include=contact,organization,assignee,team,category,tags,sla_timers,duplicate_suggestions` |
| GET | `/v1/tickets/by-number/{number}` | S | `tickets.view` | |
| PATCH | `/v1/tickets/{ticket}` | S, C | `tickets.update` | title, description, category, impact/urgency, tags |
| POST | `/v1/tickets/{ticket}/transition` | S, C | `tickets.update`; `tickets.resolve` / `tickets.close` / `tickets.reopen` by target | body `{ status, comment? }` |
| POST | `/v1/tickets/{ticket}/assign` | S | `tickets.assign` | `{ team_id?, agent_id? }`; returns ranking explanation |
| POST | `/v1/tickets/{ticket}/auto-assign` | S | `tickets.assign` | re-runs the assigner |
| POST | `/v1/tickets/{ticket}/priority` | S | `tickets.update` | `{ level, reason }` override; `{ level: null }` clears |
| POST | `/v1/tickets/{ticket}/mark-duplicate` | S | `tickets.close` | `{ duplicate_of_id }` |
| GET | `/v1/tickets/{ticket}/history` | S, C | `tickets.view` | cursor feed |
| GET | `/v1/tickets/{ticket}/duplicate-suggestions` | S | `tickets.view` | |
| POST | `/v1/duplicate-suggestions/{suggestion}/dismiss` | S | `tickets.update` | records "not a duplicate" |
| GET/POST | `/v1/tickets/{ticket}/comments` | S, C | `tickets.view` / `tickets.update`; `comments.internal` for `visibility=internal` | |
| PATCH/DELETE | `/v1/comments/{comment}` | S | author or `tickets.update` | within 15 min |
| POST | `/v1/media/intent` | S, C | `media.upload` | returns presigned PUT; optional `attach_to` (ticket/comment) |
| POST | `/v1/media/{media}/complete` | S, C | `media.upload` | verifies and activates; links to `attach_to` (requires `tickets.update`) |
| GET | `/v1/media/{media}/download` | S, C | `media.view` + access to a linked ticket | 302 to signed URL |
| GET | `/v1/media/{media}/variants/{name}` | S | `media.view` | 302 |
| GET | `/v1/media` | S, C | `media.view` | filters `folder`, `type`, `tag`, `search`, `trashed` |
| PATCH | `/v1/media/{media}` | S | `media.manage` | rename, move, tags |
| POST | `/v1/media/{media}/trash` · `/restore` | S | `media.manage` | |
| DELETE | `/v1/media/{media}` | S | `media.manage` | purge (409 if linked) |
| GET/POST/PATCH/DELETE | `/v1/media/folders[/{folder}]` | S | `media.manage` (GET: `media.view`) | |
| GET | `/v1/media/usage` | S | `media.view` | quota |
| DELETE | `/v1/tickets/{ticket}/attachments/{media}` | S | `tickets.update` | unlink |
| GET/POST/PATCH/DELETE | `/v1/calendars[/{calendar}]` (+ `/holidays`) | S | `calendars.manage` (GET: `sla.manage`) | business calendars |
| GET/PUT | `/v1/agents/{agent}/shifts` | S | `shifts.manage` (own: `agents.view`) | weekly template + exceptions |
| GET/PATCH | `/v1/settings/email` | S | `mail.manage` | sender identity, intake address, DNS records |
| GET | `/v1/inbound-emails` | S | `mail.manage` | inbound log, cursor pagination |
| POST | `/v1/tickets/bulk/assign`, `/bulk/transition` | S | `tickets.assign` / `tickets.update` | Should-have; ≤ 100 ids |

### SLA and automation settings

| Method | Path | Guard | Permission |
|---|---|---|---|
| GET | `/v1/sla-policies` | S, C (GET) | `tickets.view` |
| POST | `/v1/sla-policies` | S | `sla.manage` |
| GET/PATCH/DELETE | `/v1/sla-policies/{policy}` | S | `sla.manage` |
| POST | `/v1/sla-policies/{policy}/apply-to-open-tickets` | S | `sla.manage` |
| GET | `/v1/settings` | S | `settings.manage` (read subset for all users via `/me`) |
| PATCH | `/v1/settings/{section}` | S | `settings.manage` (`general`, `branding`, `automation`, `tickets`, `features`) |
| POST | `/v1/settings/automation/preview` | S | `settings.manage` — scores sample tickets with candidate weights |

### Notifications, reports, history, exports

| Method | Path | Guard | Permission |
|---|---|---|---|
| GET | `/v1/notifications` | S | — (own) cursor feed |
| POST | `/v1/notifications/{notification}/read`, `/read-all` | S | — (own) |
| GET | `/v1/dashboard` | S | `reports.view` — KPI tiles and the six dashboard series (`?period=`) |
| GET | `/v1/reports` | S | `reports.view` — catalogue filtered by the caller's permissions |
| GET | `/v1/reports/{report}` | S | `reports.view` — definition: dimensions, measures, filters, chart |
| POST | `/v1/reports/{report}/run` | S | `reports.view` — parameters → rows, totals, comparison |
| GET | `/v1/reports/{report}/records` | S | `reports.view` + entity view permission — drill-down, paginated |
| POST | `/v1/reports/{report}/exports` | S | `reports.export` — 202, `{format: csv|xlsx, parameters}` |
| POST | `/v1/exports/tickets` | S | `reports.export` — 202, body = current ticket-list filters |
| GET | `/v1/exports/{export}` | S | `reports.export` (own) — status + download URL |
| GET | `/v1/{tickets|contacts|organizations|agents|teams|categories}/{id}/overview` | S | entity view permission — 360 metrics and related records |
| GET | `/v1/history/{entity_type}/{id}` | S | `history.view` — change log, cursor |
| GET | `/v1/history/{entity_type}/{id}/as-of?at=` | S | `history.view` — reconstructed attributes and diff to now |
| GET/POST/PATCH/DELETE | `/v1/saved-reports[/{saved}]` | S | `reports.manage` (GET: `reports.view`) |
### Integrations, audit

| Method | Path | Guard | Permission |
|---|---|---|---|
| GET/POST | `/v1/api-clients` | S | `integrations.manage` |
| GET/PATCH | `/v1/api-clients/{client}` | S | `integrations.manage` |
| POST | `/v1/api-clients/{client}/revoke`, `/rotate-secret` | S | `integrations.manage` |
| GET/POST | `/v1/webhooks` | S, C | `integrations.manage` |
| GET/PATCH/DELETE | `/v1/webhooks/{webhook}` | S, C | `integrations.manage` |
| POST | `/v1/webhooks/{webhook}/test`, `/enable`, `/disable`, `/rotate-secret` | S, C | `integrations.manage` |
| GET | `/v1/webhooks/{webhook}/deliveries` | S, C | `integrations.manage` — cursor feed |
| GET | `/v1/webhook-deliveries/{delivery}` | S, C | `integrations.manage` |
| POST | `/v1/webhook-deliveries/{delivery}/retry` | S, C | `integrations.manage` |
| GET | `/v1/audit-logs` | S | `audit.view` — cursor feed |
| GET | `/docs/api`, `/docs/api.json` | S | any authenticated tenant user |

A route-list test asserts that every route above carries `auth` and a `can:` (or an explicit public allow-list entry) and that C-guard routes are limited to the ones marked C.
