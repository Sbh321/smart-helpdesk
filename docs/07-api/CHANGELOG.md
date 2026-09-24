# API changelog

The public REST API of Smart Helpdesk: `/v1` on the API host plus the token endpoint. Versioning and the
compatibility policy are in [versioning.md](versioning.md); the reference is generated from the code
([documentation.md](documentation.md)) and served on the docs host to workspace users with
`integrations.manage` and to Platform Super Admins.

Each entry lists what changed for integrators. The OpenAPI document (`backend/openapi.json`, also a CI
artifact) is the authority for shapes; this page is the human summary.

## Unreleased

**Added**

- `GET /v1/tickets/{ticket}/assignment` (session only, `tickets.assign`): the latest assignment row of a
  ticket with the explanation stored when it was decided. `404 not_found` when the ticket was never
  assigned. Additive; no existing shape changed.
- `GET /v1/dashboard`: each tile has `trend_series` (a series key or `null`), each series has
  `section` (`trend` or `breakdown`), and three series joined (`by_channel`, `open_by_team`,
  `ageing`); the series are now ordered trends first. Additive.
- Report definitions (`GET /v1/reports`, `GET /v1/reports/{report}`): `chart_measures` (the measures
  the chart draws by default; the parts of a `stacked_bar`) and `period_applies`. `chart` may now be
  `stacked_bar` (SLA compliance, assignment behaviour, duplicates). Additive.

## 1.0.0 — 2026-09-21

First release of the MVP surface: 147 operations, 29 of them open to API clients.

**Conventions that apply everywhere**

- **Authentication.** The SPA uses the Sanctum session cookie (`GET /sanctum/csrf-cookie`, then
  `X-XSRF-TOKEN` on unsafe methods). API clients use OAuth 2.0 client credentials: `POST /oauth/token`
  returns a one-hour bearer token ([authentication.md](authentication.md)). Every operation in the
  reference states whether API clients may call it and with which scope; the others answer a client
  with `403 forbidden`.
- **Tenant.** Taken from the session or the token, never from the host, a header or the body.
- **Errors.** RFC 9457 problem details (`application/problem+json`) with a stable `code` and a
  `request_id` equal to the `X-Request-Id` header; validation errors are `422 validation_failed` with
  `errors` per field ([errors.md](errors.md)). Every authenticated operation can answer `401
  unauthenticated`.
- **Shapes.** Single resources and lists are wrapped in `data`; paginated lists add `links` and `meta`
  (page or cursor, as each operation documents; [pagination-filtering.md](pagination-filtering.md)).
  Keys are UUID v7 strings; instants are ISO 8601 in UTC (`…Z`).
- **Idempotency.** `POST /v1/tickets` and `POST /v1/contacts` accept `Idempotency-Key`.
- **Rate limits.** 10 token requests a minute per client; 120 calls a minute per API client; report and
  ticket exports 10 a minute per user ([conventions.md](conventions.md) §Rate limits).
- **Webhooks.** Signed JSON deliveries for the v1 event catalogue ([webhooks.md](webhooks.md)).

**Operations**, grouped as in the reference. "API clients" shows the scope that opens the operation
to a client token; "—" means SPA session only, "public" means no authentication.

### Authentication

| Method | Path | Summary | API clients |
|---|---|---|---|
| POST | `/v1/auth/logout` | Sign out and destroy the session | — |
| GET | `/v1/me` | The signed-in user, their workspace and permissions | — |
| PATCH | `/v1/me/preferences` | Update interface preferences (theme, density) | — |
| POST | `/v1/auth/login` | Sign in to a workspace | public |
| POST | `/v1/auth/invitations/{token}/accept` | Accept an invitation and set a password | public |
| POST | `/v1/auth/password/forgot` | Ask for a password-reset link | public |
| POST | `/v1/auth/password/reset` | Set a new password with a reset token | public |

### API clients

| Method | Path | Summary | API clients |
|---|---|---|---|
| GET | `/v1/api-clients` | List the workspace's API clients, active ones first, newest first | — |
| POST | `/v1/api-clients` | Create an API client | — |
| GET | `/v1/api-clients/scopes` | The scopes a client can be given, with a description each | — |
| POST | `/v1/api-clients/{apiClient}/revoke` | Revoke an API client | — |
| POST | `/oauth/token` | Request an access token | public |

### Tickets

| Method | Path | Summary | API clients |
|---|---|---|---|
| POST | `/v1/tickets/bulk/assign` | Assign up to 100 tickets | — |
| POST | `/v1/tickets/preview-duplicates` | Find possible duplicates of a draft ticket | — |
| GET | `/v1/tickets/{ticket}/duplicates` | List a ticket's duplicate suggestions | — |
| POST | `/v1/tickets/{ticket}/mark-duplicate` | Mark a ticket as a duplicate | — |
| POST | `/v1/tickets/{ticket}/duplicates/{candidate}/dismiss` | Dismiss a duplicate suggestion | — |
| POST | `/v1/tickets/bulk/transition` | Transition up to 100 tickets | — |
| GET | `/v1/tickets` | List tickets | `tickets:read` |
| POST | `/v1/tickets` | Create a ticket | `tickets:write` |
| GET | `/v1/tickets/{ticket}` | Show a ticket with its contact, organisation, category and tags | `tickets:read` |
| PATCH | `/v1/tickets/{ticket}` | Edit the ticket fields that do not have their own guarded action | — |
| POST | `/v1/tickets/{ticket}/transition` | Transition a ticket | — |
| GET | `/v1/tickets/{ticket}/history` | The ticket's history, newest first (cursor pagination) | `tickets:read` |
| GET | `/v1/tickets/{ticket}/comments` | List a ticket's comments | `tickets:read` |
| POST | `/v1/tickets/{ticket}/comments` | Add a comment to a ticket | — |
| GET | `/v1/tickets/{ticket}/attachments` | List a ticket's attachments | — |
| POST | `/v1/tickets/{ticket}/attachments` | Attach media items to a ticket | — |
| DELETE | `/v1/tickets/{ticket}/attachments/{media}` | Detach a media item from a ticket | — |
| GET | `/v1/categories` | List the workspace's active categories, in display order | `tickets:read` |
| POST | `/v1/categories` | Create a category | — |
| PATCH | `/v1/categories/{category}` | Update a category | — |
| DELETE | `/v1/categories/{category}` | Delete a category | — |

### Automation

| Method | Path | Summary | API clients |
|---|---|---|---|
| POST | `/v1/tickets/{ticket}/priority` | Override or clear a ticket's priority | — |
| POST | `/v1/settings/automation/priority/preview` | Preview priority scores with draft settings | — |
| GET | `/v1/tickets/{ticket}/assignment-candidates` | Preview the assignment of a ticket | — |
| POST | `/v1/tickets/{ticket}/assign` | Assign a ticket | — |
| POST | `/v1/tickets/{ticket}/auto-assign` | Run automatic assignment for a ticket | — |
| POST | `/v1/tickets/{ticket}/unassign` | Unassign a ticket | — |

### Contacts

| Method | Path | Summary | API clients |
|---|---|---|---|
| GET | `/v1/contacts` | List contacts | `contacts:read` |
| POST | `/v1/contacts` | Create a contact | `contacts:write` |
| GET | `/v1/contacts/typeahead` | Find contacts as you type | `contacts:read` |
| GET | `/v1/contacts/{contact}` | Show a contact | `contacts:read` |
| PATCH | `/v1/contacts/{contact}` | Update a contact | `contacts:write` |
| POST | `/v1/contacts/{contact}/archive` | Archive a contact | — |
| POST | `/v1/contacts/{contact}/unarchive` | Restore an archived contact | — |
| GET | `/v1/organizations` | List organisations | `contacts:read` |
| POST | `/v1/organizations` | Create an organisation | `contacts:write` |
| GET | `/v1/organizations/{organization}` | Show an organisation | `contacts:read` |
| PATCH | `/v1/organizations/{organization}` | Update an organisation | `contacts:write` |

### Tags

| Method | Path | Summary | API clients |
|---|---|---|---|
| GET | `/v1/tags` | List tags, optionally matching a search term (for the tag picker) | `tickets:read` |
| POST | `/v1/tags` | Create a tag | — |
| DELETE | `/v1/tags/{tag}` | Delete a tag and remove it from everything it labels | — |

### Agents

| Method | Path | Summary | API clients |
|---|---|---|---|
| GET | `/v1/agents` | List agents | — |
| POST | `/v1/agents` | Make a user an agent | — |
| GET | `/v1/agents/available-users` | List users who can become agents | — |
| GET | `/v1/agents/{agent}` | Get an agent | — |
| PATCH | `/v1/agents/{agent}` | Update an agent | — |
| DELETE | `/v1/agents/{agent}` | Remove an agent profile | — |
| GET | `/v1/agents/{agent}/workload` | Get an agent's live workload | — |
| GET | `/v1/agents/{agent}/shifts` | List an agent's shifts | — |
| PUT | `/v1/agents/{agent}/shifts` | Replace an agent's shifts | — |
| GET | `/v1/skills` | List skills | — |
| POST | `/v1/skills` | Create a skill | — |
| PATCH | `/v1/skills/{skill}` | Update a skill | — |
| DELETE | `/v1/skills/{skill}` | Delete a skill | — |
| GET | `/v1/teams` | List teams | — |
| POST | `/v1/teams` | Create a team | — |
| PATCH | `/v1/teams/{team}` | Update a team | — |
| DELETE | `/v1/teams/{team}` | Delete a team | — |
| PUT | `/v1/teams/{team}/members` | Replace a team's members | — |

### SLA

| Method | Path | Summary | API clients |
|---|---|---|---|
| GET | `/v1/calendars` | List business calendars | — |
| POST | `/v1/calendars` | Create a business calendar | — |
| GET | `/v1/calendars/{calendar}` | Get a business calendar | — |
| PATCH | `/v1/calendars/{calendar}` | Update a business calendar | — |
| DELETE | `/v1/calendars/{calendar}` | Delete a business calendar | — |
| POST | `/v1/calendars/{calendar}/holidays` | Add a holiday to a calendar | — |
| DELETE | `/v1/calendars/{calendar}/holidays/{holiday}` | Remove a holiday from a calendar | — |
| GET | `/v1/sla-policies` | List SLA policies | — |
| POST | `/v1/sla-policies` | Create an SLA policy | — |
| GET | `/v1/sla-policies/{policy}` | Get an SLA policy | — |
| PATCH | `/v1/sla-policies/{policy}` | Update an SLA policy | — |
| DELETE | `/v1/sla-policies/{policy}` | Delete an SLA policy | — |
| GET | `/v1/tickets/{ticket}/sla` | List a ticket's SLA timers | — |

### Media

| Method | Path | Summary | API clients |
|---|---|---|---|
| POST | `/v1/media/intent` | Start an upload | — |
| GET | `/v1/media/folders` | List media folders | — |
| POST | `/v1/media/folders` | Create a media folder | — |
| PATCH | `/v1/media/folders/{folder}` | Rename or move a media folder | — |
| DELETE | `/v1/media/folders/{folder}` | Delete a media folder | — |
| GET | `/v1/media` | List media items | — |
| GET | `/v1/media/usage` | Get storage usage | — |
| POST | `/v1/media/{media}/complete` | Complete an upload | — |
| GET | `/v1/media/{media}` | Get a media item | — |
| PATCH | `/v1/media/{media}` | Update a media item | — |
| DELETE | `/v1/media/{media}` | Delete a media item permanently | — |
| GET | `/v1/media/{media}/download` | Download a media item | — |
| GET | `/v1/media/{media}/variants/{name}` | Download a variant of a media item | — |
| POST | `/v1/media/{media}/trash` | Move a media item to the trash | — |
| POST | `/v1/media/{media}/restore` | Restore a media item from the trash | — |

### Notifications

| Method | Path | Summary | API clients |
|---|---|---|---|
| GET | `/v1/notifications` | Unread first, then newest first | — |
| POST | `/v1/notifications/read-all` | Mark every notification read | — |
| POST | `/v1/notifications/{notification}/read` | Mark a notification read | — |

### Reports

| Method | Path | Summary | API clients |
|---|---|---|---|
| GET | `/v1/dashboard` | Get the dashboard | — |
| GET | `/v1/reports` | The reports the caller may run | — |
| GET | `/v1/reports/{report}` | Get a report definition | — |
| POST | `/v1/reports/{report}/run` | Run a report | — |
| GET | `/v1/reports/{report}/records` | List the records behind a report number | — |
| POST | `/v1/reports/{report}/exports` | Export a report | — |
| POST | `/v1/exports/tickets` | Export the ticket list | — |
| GET | `/v1/exports/{export}` | Show one of my exports | — |
| GET | `/v1/tickets/{id}/overview` | Get a ticket overview | — |
| GET | `/v1/contacts/{id}/overview` | Get a contact overview | — |
| GET | `/v1/organizations/{id}/overview` | Get an organisation overview | — |
| GET | `/v1/agents/{id}/overview` | Get an agent overview | — |
| GET | `/v1/teams/{id}/overview` | Get a team overview | — |
| GET | `/v1/categories/{id}/overview` | Get a category overview | — |

### History

| Method | Path | Summary | API clients |
|---|---|---|---|
| GET | `/v1/history/{type}/{id}` | Newest change first, 50 a page | — |
| GET | `/v1/history/{type}/{id}/as-of` | Get a record as of an instant | — |

### Users

| Method | Path | Summary | API clients |
|---|---|---|---|
| GET | `/v1/users` | List workspace users | — |
| POST | `/v1/users/invitations` | Invite a user | — |
| GET | `/v1/users/{user}` | Get a workspace user | — |
| PATCH | `/v1/users/{user}` | Update a user | — |
| POST | `/v1/users/{user}/invitation` | Resend an invitation | — |
| POST | `/v1/users/{user}/disable` | Disable a user | — |
| POST | `/v1/users/{user}/enable` | Enable a user | — |

### Roles and permissions

| Method | Path | Summary | API clients |
|---|---|---|---|
| GET | `/v1/permissions` | The permission catalogue, grouped by resource | — |
| GET | `/v1/roles` | List the roles available in this workspace | — |
| POST | `/v1/roles` | Create a custom role | — |
| PATCH | `/v1/roles/{role}` | Change a custom role's name or permissions | — |
| DELETE | `/v1/roles/{role}` | Delete a custom role | — |

### Settings

| Method | Path | Summary | API clients |
|---|---|---|---|
| GET | `/v1/settings` | Every section with its effective values | — |
| GET | `/v1/settings/{section}` | One section with its effective values and defaults | — |
| PATCH | `/v1/settings/{section}` | Update a settings section | — |

### Webhooks

| Method | Path | Summary | API clients |
|---|---|---|---|
| GET | `/v1/webhooks` | List the workspace's webhook subscriptions, newest first | `webhooks:manage` |
| POST | `/v1/webhooks` | Create a webhook subscription | `webhooks:manage` |
| GET | `/v1/webhooks/events` | The event catalogue a subscription can listen to | `webhooks:manage` |
| GET | `/v1/webhooks/{webhook}` | Get a webhook subscription | `webhooks:manage` |
| PATCH | `/v1/webhooks/{webhook}` | Edit the name, URL or events of a subscription | `webhooks:manage` |
| DELETE | `/v1/webhooks/{webhook}` | Delete a subscription and its delivery log | `webhooks:manage` |
| POST | `/v1/webhooks/{webhook}/enable` | Enable a webhook subscription | `webhooks:manage` |
| POST | `/v1/webhooks/{webhook}/disable` | Disable a webhook subscription | `webhooks:manage` |
| POST | `/v1/webhooks/{webhook}/rotate-secret` | Rotate the signing secret | `webhooks:manage` |
| POST | `/v1/webhooks/{webhook}/test` | Send a test delivery | `webhooks:manage` |
| GET | `/v1/webhooks/{webhook}/deliveries` | The subscription's delivery log, newest first (cursor pagination) | `webhooks:manage` |
| GET | `/v1/webhook-deliveries/{delivery}` | One delivery with the envelope that is sent | `webhooks:manage` |
| POST | `/v1/webhook-deliveries/{delivery}/retry` | Retry a delivery | `webhooks:manage` |

### System

| Method | Path | Summary | API clients |
|---|---|---|---|
| GET | `/v1/ping` | Ping | public |

**Known limits in 1.0.0**

- Ticket edits, transitions, comments and media are SPA-only; API clients read tickets and their public
  comments and create tickets (MVP-SHORTCUT in `Tickets/Routes/api.php`).
- `PATCH /v1/settings/{section}` documents its body as a free-form object: the keys differ per section
  and are listed by `GET /v1/settings/{section}` (`defaults`).
- `priority_explanation`, assignment `explanation`, `metadata`, `external_ids` and the history
  `old`/`new` values are free-form objects on purpose (their keys depend on the strategy or the record).
