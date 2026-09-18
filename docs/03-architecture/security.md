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
| history.view | ✔ | ✔ | ✔ | tickets only | — |

## Threat model (STRIDE-lite)

| Threat | Vector | Mitigation |
|---|---|---|
| Tenant data leakage | forgotten scope, raw query, wrong join | global scopes + RLS + reflection tests; `DB::` raw queries only in `Queries/` with tenant predicate and RLS |
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
| Malicious uploads | executable/HTML files | MIME allow-list, size limit, real MIME sniff on complete, `Content-Disposition: attachment`, storage bucket private, no inline rendering of user HTML |
| XSS | ticket text | React escaping; Markdown rendered through a sanitiser allow-list (no raw HTML); CSP `default-src 'self'` with hashed inline styles from the Tailwind build and a hashed inline theme boot script ([themes.md](../06-design-system/themes.md)) |
| CSRF | cookie sessions | Sanctum CSRF; SameSite=Lax; state-changing routes POST/PUT/PATCH/DELETE only |
| SQL injection | raw SQL | Eloquent bindings; raw FTS uses bindings; Larastan |
| Rate limiting | abuse | per-route throttles (login, API, uploads); per-client limits |
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

## Security headers (Caddy)

`Strict-Transport-Security`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`, `Content-Security-Policy` (SPA: `default-src 'self'; img-src 'self' data: <storage-endpoint>; connect-src 'self' <storage-endpoint> wss://<host>`), `Permissions-Policy` minimal.

## Audit

Security-relevant actions are recorded in `audit_logs` ([04-domain/audit.md](../04-domain/audit.md)); auth events (login success/failure, password reset) included.

## Out of MVP scope

MFA, SSO, IP allow-lists, field-level encryption, malware scanning, SOC2-style controls; listed in the [V1 backlog](../../roadmap/09-v1-backlog.md).
