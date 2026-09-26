# Security model

Threat model and controls for the MVP. Test mapping in [10-quality/security-testing.md](../10-quality/security-testing.md). Auth decisions in [ADR-0007](../adr/0007-authentication-and-oauth.md).

## Identity and access

| Concern | Control |
|---|---|
| SPA authentication | Sanctum session cookie (HttpOnly, Secure, SameSite=Lax) on `.shp…` shared by `app` and `api`, with the tenant fixed in the session at login; CSRF token via `XSRF-TOKEN` cookie and `PreventRequestForgery` |
| Passwords | bcrypt (Laravel default, cost 12); minimum 12 characters; breached-password check via `Password::uncompromised()` (offline-safe fallback when no network) |
| Brute force | `throttle:login` 5/min per email+IP; exponential lockout after 10 failures per account for 15 min |
| Invitations / resets | signed, single-use, 48 h expiring URLs; tokens hashed at rest |
| API clients | Passport client credentials; secret shown once, hashed; tokens 1 h; scopes = permissions; per-client rate limit |
| Roles and permissions | spatie/laravel-permission with tenant teams; granular `resource.action` permissions; every route declares `can:`; policies for record-level rules; last Tenant Owner cannot be removed |
| Platform admins | separate guard and table; central domain only; MFA is V1 |

### Permission matrix (default roles)

| Permission | Owner | Admin | Manager | Agent | Developer |
|---|---|---|---|---|---|
| tickets.view / create / update | ✔ | ✔ | ✔ | ✔ | ✔ (API) |
| tickets.assign / resolve / close / reopen | ✔ | ✔ | ✔ | ✔ (own) | — |
| tickets.delete | ✔ | ✔ | — | — | — |
| comments.internal | ✔ | ✔ | ✔ | ✔ | — |
| contacts.view / manage | ✔ | ✔ | ✔ | view | ✔ (API) |
| agents.view / manage, teams.manage | ✔ | ✔ | manage (availability) | view | — |
| sla.manage, settings.manage | ✔ | ✔ | — | — | — |
| users.manage, roles.manage | ✔ | ✔ | — | — | — |
| integrations.manage | ✔ | ✔ | — | — | ✔ |
| reports.view | ✔ | ✔ | ✔ | — | — |
| audit.view | ✔ | ✔ | — | — | — |
| media.view / upload | ✔ | ✔ | ✔ | ✔ | ✔ (API) |
| media.manage | ✔ | ✔ | ✔ | — | — |
| calendars.manage, shifts.manage | ✔ | ✔ | shifts | — | — |
| mail.manage (sender identity, inbound log) | ✔ | ✔ | — | — | — |
| reports.view, reports.export | ✔ | ✔ | ✔ | own performance only | — |
| reports.manage (saved/scheduled) | ✔ | ✔ | ✔ | — | — |
| history.view | ✔ | ✔ | ✔ | tickets only (see §History as built) | — |

## Threat model (STRIDE-lite)

| Threat | Vector | Mitigation |
|---|---|---|
| Tenant data leakage | forgotten scope, raw query, wrong join | global scopes + RLS + reflection tests; `DB::` raw queries only in `Queries/` with tenant predicate and RLS. Since M3-07 the policies are forced on every tenant table and the runtime role cannot bypass them, so a raw query that forgets the tenant sees only the current tenant, or nothing outside one ([08-database/tenancy.md](../08-database/tenancy.md)) |
| IDOR | guessing IDs | UUID v7; tenant scope makes foreign IDs 404; ownership policies for `own` permissions |
| Authorisation bypass | missing `can:` | route-list test asserts every `/api` route has auth + permission middleware (allow-list of public routes) |
| Header/host spoofing | attacker-controlled Host or workspace value | Caddy only forwards the fixed hosts; the tenant comes from the session or client, never from Host or headers; the workspace in the SPA URL is display-only; membership check on every request |
| Cross-tenant script injection on the shared `app` origin | stored XSS in ticket content | sanitised Markdown, React escaping, strict CSP without `unsafe-inline` scripts, tests with XSS payloads ([ADR-0021](../adr/0021-host-layout-and-tenant-resolution.md) consequences) |
| OAuth token exposure | leaked bearer | short TTL, scopes, revocation UI, audit of client creation; tokens never logged |
| API key exposure | secret in repo | only client-credential secrets exist; shown once; hashed |
| Webhook forgery | attacker posts to receiver | HMAC-SHA256 over `timestamp.body` with per-subscription secret; `X-Helpdesk-Signature`, `X-Helpdesk-Timestamp`, `X-Helpdesk-Event-Id` |
| Webhook replay | resend captured delivery | timestamp tolerance 5 min + event id for receiver idempotency; documented verification snippet |
| SSRF via webhook URL | internal addresses | URL validation: https only in production, deny private/link-local ranges, resolve-and-check before send, no redirects |
| Email spoofing / forged replies | attacker sends mail as a contact or forges a `ticket+uuid` address | SPF/DKIM/DMARC results recorded by the mail server; reply accepted only from the ticket's contact (or allowed org domain); UUID checked against the tenant; rejected messages logged |
| Inbound spoofing, as built (M3-19) | a forged `ticket+<uuid>@` for another workspace's ticket, a stranger writing on a ticket, a mail loop, a mail bomb | the address never selects a workspace by itself: the ticket id is looked up inside each active workspace under row-level security; a message that also names an intake address must name the ticket's own workspace (`tenant_mismatch`); the sender must be the ticket's requester or an active contact of the ticket's organisation (`sender_not_allowed`); both are logged as `rejected` in the ticket's workspace and the workspace's mail admins and the assignee are told in-app (never by mail). Our Message-IDs are only trusted on the mail domain. Auto-replies and bounces (RFC 3834 `Auto-Submitted`, `X-Autoreply`, `Precedence`, RFC 3464 reports, null sender, `mailer-daemon`) are logged as `ignored`, and nothing is ever mailed back to an inbound sender, so no loop can start. Messages above 30 MiB are rejected without their body; attachments follow the upload allow-list, size limit, MIME sniffing and quota; unknown senders create contacts only when the workspace allows it (Settings → Email). MVP-SHORTCUT: SPF/DKIM/DMARC results are not read from `Authentication-Results`, so a forged From of a real contact passes the sender check; V1: V1-ML-08 |
| Outbound spoofing and header injection (M3-18) | a workspace or an attacker sends as another workspace, a bank, or injects headers through the sender name | only `app@` may send as any address of the mail domain, other Stalwart accounts must match their sender (`mustMatchSender`); SMTP AUTH on 587 only, 587 and the admin port are never published; the sender name is a display name only (≤ 80 characters, no line breaks, quotes, angle brackets, backslash or `@`) and Symfony Mime encodes it; the From address is always the workspace's own `support+<slug>@` |
| Open relay (M3-18) | anyone relays mail through port 25 | Stalwart relays only for authenticated sessions (`allowRelaying = !is_empty(authenticated_as)`), and port 25 never authenticates; submission on 587 is plaintext inside the Docker network only (MVP-SHORTCUT: no TLS between the app and Stalwart; V1: V1-ML-06) |
| Malicious uploads | executable/HTML files | MIME allow-list, size limit, real MIME sniff on complete, `Content-Disposition: attachment` for downloads, storage bucket private, no inline rendering of user HTML; the lightbox's `GET /media/{id}/open` is inline only for raster images, PDF and plain text (CSV and logs as `text/plain`), with the content type fixed from the sniffed type and served from the `files` host ([storage §Download flow](storage.md#download-flow)) |
| XSS | ticket text | React escaping; Markdown rendered through a sanitiser allow-list (no raw HTML); CSP `default-src 'self'` with hashed inline styles from the Tailwind build and a hashed inline theme boot script ([themes.md](../06-design-system/themes.md)) |
| CSRF | cookie sessions | Sanctum CSRF; SameSite=Lax; state-changing routes POST/PUT/PATCH/DELETE only |
| SQL injection | raw SQL | Eloquent bindings; raw FTS uses bindings; Larastan |
| Rate limiting | abuse | per-route throttles (login, API, uploads); per-client limits |
| Sign-up abuse | fake workspaces, mail bombing | nothing exists before the emailed link is followed; honeypot field; 5 requests per hour per client and 3 per address, 30 address checks and 10 link redemptions a minute (`SIGNUP_LIMIT_*`, `helpdesk.platform.signup_limits`; 0 turns a limit off, which local development and demos do, production keeps these values); reserved and existing addresses refused at request and again at verification; a platform setting closes sign-up ([ADR-0025](../adr/0025-plans-subscriptions-and-receipts.md) §8) |
| Platform admin accounts | a lost or departed admin | invitations single use for 48 hours, stored hashed; resets through their own broker (60 minutes, neutral answer for unknown addresses); deactivation ends the session and the docs and monitor passes on the next request; nobody deactivates themselves (§7) |
| Billing data | one workspace reading another's payments | subscriptions and payments are central rows filtered by the resolved workspace on the two workspace endpoints, covered by isolation tests; receipts open only with `billing.manage` (§5) |
| Secrets management | `.env` in repo | `.env.example` only; Ansible writes `.env` 0600; DB password via Compose secret; CI secrets in GitHub |
| Redis/Valkey exposure | public port | no published port in prod; Docker network only; `requirepass` set |
| Object storage exposure | public bucket | private bucket; presigned URLs 5 min; console port not published in prod |
| PostgreSQL exposure | public port | not published; separate owner/app roles; `scram-sha-256` |
| Logs leaking PII | debug logs | no request bodies in logs; Telescope dev-only; log-viewer platform-admin only |
| Supply chain | dependencies | lockfiles; `composer audit` and `pnpm audit` in CI; Dependabot |

## Roles and permissions as built (M1-09)

The catalogue lives in `App\Modules\Identity\Support\PermissionCatalogue` and is written to the
database by `php artisan identity:sync-permissions` (also run by `DatabaseSeeder`). It is idempotent:
it adds missing permissions and re-syncs the five global default roles, leaving custom roles alone.

| Role | Gets |
|---|---|
| `owner` | every permission |
| `admin` | everything except nothing; manager plus settings, users, roles, integrations, mail and audit |
| `manager` | agent plus assign, delete, agents, teams, SLA, calendars, shifts, media management |
| `agent` | ticket work, internal notes, contacts, media upload, reports |
| `developer` | read-only ticket, contact and agent views plus integrations |

Details that matter when adding endpoints:

- Roles live in `roles` with a nullable `tenant_id`; null marks the five global defaults, which every
  workspace inherits. Custom roles carry the tenant and are unique per workspace, and a custom role
  may not reuse a default role's name.
- Assignments always carry `tenant_id` (spatie's team key), so the same user row cannot gain another
  workspace's permissions. Outside a request, assign roles inside `$tenant->run(...)`.
- `User::$guard_name` is pinned to `web`, so a session request and a token request resolve the same
  permissions (ADR-0007: one vocabulary).
- Every `/v1` route declares `can:<permission>`. `tests/Permissions/RouteProtectionTest.php` fails
  when a route has no permission and no allow-list entry, when a route is unauthenticated, when a
  permission is not in the catalogue, or when a platform route lacks the platform guard.
- `tests/Permissions/RoleMatrixTest.php` is the roles × routes table; rows are added with each
  endpoint.
- `LastOwnerGuard` refuses to remove the last active owner of a workspace.
- Invitations name roles; acceptance assigns the ones that still exist.

## Users and role assignment as built (M2-12)

`/v1/users` (Settings → Users, `users.manage`): list with status (`active`, `invited`, `disabled`), role
and text filters; invite; change name and roles; resend an invitation; disable and enable.

- **Invitation.** Inviting creates the user without a password and a single-use, 48-hour invitation
  that carries the roles; the roles are given on acceptance. Until then the user is listed as
  `invited` and a role change edits the invitation. Resending issues a new link and ends the old one.
  Invitations are throttled to 20 a minute per user because each one sends mail.
- **Role assignment** (`RoleAssignmentGuard`), checked on invite, role change and disable:
  1. Only an owner gives or takes the `owner` role. Owner and admin hold the same permissions, so a
     permission check alone would let an admin make themselves owner.
  2. A role may be given or taken only when the actor holds every one of its permissions.
  Refusals are 403 `forbidden` with `meta.roles`.
- **Role editing.** A permission may be added to or removed from a custom role only by a user who
  holds it: editing a role reaches everyone who holds it, the editor included. A custom role that users
  still hold cannot be deleted (409 `in_use`, `meta.users`).
- **Disable.** A disabled user cannot sign in; their sessions and personal access tokens are deleted
  at once and automatic assignment skips them. Nobody disables their own account (409 `conflict`,
  `meta.reason: self`), and the last active owner keeps the role and the account (422 `last_owner`).
- **Audit.** `user.invited`, `user.invitation_resent`, `user.role_changed` (old and new roles),
  `user.disabled`, `user.enabled`.

## History as built (M2-13)

`history.view` is in the catalogue and held by owner, admin and manager. The matrix gives agents
"tickets only"; since no single permission can say that, the history API opens the ticket family
(`tickets`, `ticket_comments`, `ticket_assignments`, `ticket_sla_timers`, `ticket_duplicate_suggestions`,
`sla_events`) to readers who hold `tickets.view` and `comments.internal` (agents, not developers). Every
subject also needs its own view permission (`HistorySubjects::SUBJECTS`): `contacts.view` for contacts
and organisations, `agents.view` for the directory, `media.view` for media, `users.manage` for users
and `settings.manage` for workspace settings. Unknown subjects and records of another workspace are 404.

## API clients as built (M3-04)

Details in [07-api/authentication.md](../07-api/authentication.md) §3 As built.

- **Scopes, not roles.** A client holds no role. `Gate::before` answers every ability for an API client
  from `Integrations\Domain\ScopeMap` alone: `tickets:read` → `tickets.view`; `tickets:write` →
  `tickets.create`, `tickets.update`, `tickets.resolve`, `tickets.close`; `contacts:read` →
  `contacts.view`; `contacts:write` → `contacts.manage`; `catalog:read` → `agents.view`;
  `webhooks:manage` → `integrations.manage`. No scope grants `tickets.assign`, `tickets.delete`,
  `comments.internal`, `settings.manage`, `users.manage`, `roles.manage`, `audit.view` or
  `history.view`; `tests/Unit/Integrations/ScopeMapTest.php` checks the map against the catalogue.
- **Where, not only what.** A client reaches only routes marked `api-clients`; everything else,
  including `/v1/api-clients` and `/v1/me`, is 403 whatever its scopes.
- **Tenant binding.** The tenant comes from the token's stored row; the guard loads the client only
  inside that tenant, so a token never works for another workspace, and a suspended workspace's
  clients get no tokens.
- **Revocation** is immediate: the guard checks the client and the token row on every request.
- **Secrets**: 48 random characters, shown once in the create response, stored as a bcrypt hash.
  Signing keys live in `storage/oauth-*.key` (git-ignored, 0600/0660) or `PASSPORT_*_KEY`.
- **Actor.** Everything a client does is recorded as `api_client` with the client id, in `audit_logs`
  and in `entity_changes`; tickets it creates carry `created_via = api` and `created_by_client_id`.
- **Rate limits.** 10 token requests a minute per client id, 120 API calls a minute per client.
- `integrations.manage` (owner, admin, developer) manages clients in Settings → API clients; every
  create and revoke is audited (`api_client.created`, `api_client.revoked`).

## Webhook SSRF guard as built (M3-05)

`Integrations\Webhooks\UrlGuard` runs when a webhook URL is saved (422 `webhook_url_rejected`) and again
right before every delivery (the delivery fails with `url_rejected: <reason>`), because DNS may change:

- `https` only, no user info, port 443 or 8443, and not one of the platform's own hosts
  (`PLATFORM_DOMAIN` and subdomains, `localhost`, `*.localhost`);
- every A and AAAA record must be public unicast (`IpAddressPolicy`): loopback, RFC 1918, CGNAT,
  link-local including the cloud metadata address `169.254.169.254`, multicast, documentation and reserved
  ranges are refused, for IPv6 too (`::1`, ULA `fc00::/7`, link-local `fe80::/10`, …), and an IPv4 address
  embedded in IPv6 (mapped, NAT64, 6to4) is checked as IPv4; an unresolvable host is refused;
- the request connects to the address that was checked (`CURLOPT_RESOLVE`), so a DNS rebinding between
  the check and the send cannot move it; redirects are not followed (a 3xx is a failed attempt); timeout
  10 s; at most 1 KB of the response is read.

`WEBHOOK_DEV_ALLOWED_HOSTS` (`helpdesk.webhooks.dev_allowed_hosts`) lists host names — never ranges — that
skip the scheme, port and address checks in development (the Compose `webhook-echo` service). It is
ignored when `APP_ENV=production`. Secrets are 32 random bytes (base64), stored with the `encrypted` cast,
returned only by the create and rotate responses, and never written to logs or audit entries. Tests:
`tests/Unit/Integrations/IpAddressPolicyTest.php`, `tests/Feature/Integrations/WebhookUrlGuardTest.php`.

## Row-level security as built (M3-07)

Every table in `TenantTables::all()` has row-level security enabled and forced with a `tenant_isolation`
policy on `app.current_tenant` ([08-database/tenancy.md](../08-database/tenancy.md)). The application,
Horizon and the scheduler connect as `helpdesk_app`: no ownership, no `BYPASSRLS`, no superuser and no
`TRUNCATE` on tenant tables, all asserted by the isolation suite. The owner connection runs migrations only;
nothing at runtime bypasses the policies, and cross-tenant work (provisioning, sweeps, retention) enters each
tenant in turn. Without a tenant a tenant table shows no rows and refuses inserts, so a forgotten scope or a
raw query fails closed rather than leaking.

Residual risks: the setting lives on the PostgreSQL session, so a connection pooler in transaction mode
(PgBouncer) must not be introduced without moving to `SET LOCAL` per transaction; `helpdesk_backup`
(`BYPASSRLS`, read-only) is a high-value credential and is kept for `pg_dump` only; `sessions`,
`oauth_clients`, `oauth_access_tokens`, `personal_access_tokens`, `tenant_counters` and
`password_reset_tokens` carry `tenant_id` but no policy, because they are read before a tenant is known,
and rely on the application checks and their tests.

## Platform documentation and monitoring access (ADR-0024, M5-06)

`platform-docs.<domain>` (the built `docs/` site) and `monitor.<domain>` (Horizon, Telescope in local, the health dashboard, the RustFS console, the Stalwart admin API) serve only a Platform Super Admin holding a platform pass. Since the 2026-09-25 amendment the pass is a random token held in the cache, recorded in the console session that handed it out and deleted at console sign-out; the cookie is `shp_platform_pass`; the monitor host no longer uses basic auth. The console's `POST /platform-api/docs/handoff` (platform guard) returns a link signed with HMAC-SHA256 over admin id, expiry, nonce and target path with the application key, valid 60 s and single use (nonce kept in the cache). `/_session/start` exchanges it for `shp_platform_docs`: host-only (built with `Cookie::create`, because Laravel's `cookie()` would fill in `session.domain` and send it to every host), `Secure`, `HttpOnly`, `SameSite=Lax`, encrypted by `EncryptCookies`, eight hours. Caddy's `forward_auth` asks `/_session/check` before every file: 204 for a valid pass of an existing admin, otherwise a redirect to the console's `/platform/docs?next=…`, which requires the platform sign-in and hands off again. `next` is limited to same-host paths on both sides. The routes have no session, tenant or CSRF middleware. Tests: `tests/Feature/Platform/PlatformDocsAccessTest.php` (hand-off, single use, expiry, tampering, guests, a hand-written cookie without the key, a tenant session, deleted admin, host-only attributes).

## Security headers (Caddy)

`Strict-Transport-Security`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`, `Content-Security-Policy` (SPA: `default-src 'self'; img-src 'self' data: <storage-endpoint>; connect-src 'self' <storage-endpoint> wss://<host>`), `Permissions-Policy` minimal.

## Audit

Security-relevant actions are recorded in `audit_logs` ([04-domain/audit.md](../04-domain/audit.md)); auth events (login success/failure, password reset) included.

## Out of MVP scope

MFA, SSO, IP allow-lists, field-level encryption, malware scanning, SOC2-style controls; listed in the [V1 backlog](../../roadmap/09-v1-backlog.md).
