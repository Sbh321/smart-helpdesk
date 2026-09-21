# ADR-0021 Host layout on `shp.subhambhandari.com.np` and tenant resolution without tenant subdomains

**Status:** Accepted (2026-09-17). Supersedes decision 3 (identification) of [ADR-0006](0006-multi-tenancy-model.md) and the per-tenant TLS part of [ADR-0012](0012-deployment-architecture.md). Data isolation (shared schema, stancl single-database mode, RLS) is unchanged.

## Context

The owner will host the platform under a personal domain, `subhambhandari.com.np`, using the prefix `shp` (Smart HelP desk) and one sub-subdomain per function: `app`, `api`, `admin`, `monitor`, `docs`. With a fixed set of hosts, tenants can no longer be identified by their own subdomain. `.com.np` is a public suffix, so all `*.shp.subhambhandari.com.np` hosts are **same-site** (site = `subhambhandari.com.np`), which keeps Sanctum's cookie-based SPA authentication working across `app` and `api`.

## Decision

### Hosts (`PLATFORM_DOMAIN=shp.subhambhandari.com.np`)

| Host | Serves | Access |
|---|---|---|
| `shp.subhambhandari.com.np` | product landing page (static); `/` links to the app | public |
| `app.shp.subhambhandari.com.np` | tenant SPA; workspace in the path: `/{workspace}/tickets`, `/{workspace}/reports` … | tenant users |
| `api.shp.subhambhandari.com.np` | REST API `/v1/*`, `/oauth/token`, `/sanctum/csrf-cookie`, `/broadcasting/auth`, websockets `/app` (Reverb) | SPA sessions, API clients |
| `admin.shp.subhambhandari.com.np` | platform-admin SPA and same-origin platform API `/platform-api/*` | platform super admins (separate guard, host-only cookie) |
| `monitor.shp.subhambhandari.com.np` | Horizon, health dashboard, log viewer, mail-server admin, object-storage console | platform super admins (app gate + Caddy basic auth; optional IP allow-list) |
| `docs.shp.subhambhandari.com.np` | API reference (Scramble) and, later, the user guide | public at first; since M3-06 the reference needs a workspace user with `integrations.manage` (docs/07-api/documentation.md §Access) |
| `files.shp.subhambhandari.com.np` | S3-compatible endpoint (RustFS) used by presigned upload/download URLs | signed URLs only |
| `mail.shp.subhambhandari.com.np` | SMTP (25, 587) and IMAP (993) of the bundled mail server; MX target | mail |

Email addresses use the platform domain: `no-reply@shp.subhambhandari.com.np`, `support+<workspace>@shp.subhambhandari.com.np`, `ticket+<uuid>@shp.subhambhandari.com.np`.

Reserved workspace slugs: `app, api, admin, monitor, docs, files, mail, www, login, logout, invite, reset-password, select-workspace, assets, static, platform, health, status`.

Local development mirrors the layout under `shp.localhost` (browsers resolve `*.localhost` to loopback): `app.shp.localhost`, `api.shp.localhost`, `admin.shp.localhost`, `monitor.shp.localhost`, `docs.shp.localhost`, `files.shp.localhost`, `mail.shp.localhost` (Mailpit UI).

On-premise installs may use **single-host mode** (`HOST_LAYOUT=single`): one hostname serves the SPA at `/`, the API at `/api`, docs at `/docs` and monitoring at `/monitor`, with `TENANCY_SINGLE_TENANT` set so no workspace segment is needed.

### Tenant resolution (no client-supplied tenant after login)

| Request | Tenant source | Check |
|---|---|---|
| Pre-authentication (`/v1/auth/login`, accept invitation, password reset) | `workspace` slug in the request body or signed link | tenant exists and is active; credentials verified inside that tenant |
| SPA session | `tenant_id` written into the session at login | loaded user's `tenant_id` must equal the session tenant, else the session is destroyed (401) |
| API client bearer token | `oauth_clients.tenant_id` of the token's client | client not revoked; tenant active |
| Platform admin (`admin` host) | none (central) | platform guard |
| Inbound email | workspace or ticket UUID in the recipient address | UUID must belong to the routed tenant |

Middleware order on `api`: `ResolveTenantFromPrincipal` (reads session `tenant_id` or the bearer client **before** loading the user) → tenancy initialisation (RLS setting, permission team, prefixes) → `auth:sanctum,api` → `EnsureTenantMembership` → `SubstituteBindings` → throttle. Credential tables needed before initialisation (`sessions`, `oauth_clients`, `oauth_access_tokens`, `personal_access_tokens`) are central tables without RLS but carry `tenant_id`.

The SPA's URL workspace segment is **presentation only**: after login the SPA compares it with the session tenant from `/v1/me` and redirects on mismatch. It is never sent to the API as a tenant selector.

### Cookies, CORS and TLS

- `SESSION_DOMAIN=.shp.subhambhandari.com.np` (needed so the SPA can read `XSRF-TOKEN`), `SANCTUM_STATEFUL_DOMAINS=app.shp.subhambhandari.com.np`, CORS on `api` allows origin `https://app.shp…` with credentials. Cookies are `Secure`, `HttpOnly` (except XSRF), `SameSite=Lax`.
- The platform-admin session uses a different cookie name and is host-only on `admin`, so a tenant session can never authenticate platform routes.
- TLS: a fixed host list, so Caddy obtains ordinary per-host ACME certificates. No wildcard certificate and no on-demand TLS are needed. DNS: A/AAAA records for each host (or a wildcard `*.shp` A record), MX for `shp.subhambhandari.com.np` → `mail.shp…`, SPF/DKIM/DMARC TXT records, PTR for the VM address.

## Alternatives considered

- **Tenant subdomains** (`acme.shp…`), the previous decision: cookie isolation per tenant, but conflicts with the requested `app`/`api` layout and needs wildcard or on-demand TLS. Kept as the V1 path for **custom tenant domains** (`support.acme.com` CNAME → `app.shp…`, on-demand TLS).
- **`X-Tenant` header** from the SPA: rejected again; the session already knows the tenant.
- **Workspace in the API path** (`/v1/w/acme/...`): verbose for integrators whose token already determines the tenant.

## Consequences

- Users belong to exactly one tenant in the MVP; a user who needs two workspaces uses two accounts. Multi-workspace users (V1) would add a tenant switcher that rewrites the session tenant after a membership check.
- Tenants lose per-tenant cookie origins; XSS in one tenant's content would run on the shared `app` origin. The mitigations in [security.md](../03-architecture/security.md) (React escaping, sanitised Markdown, strict CSP) become more important and are tested.
- Operations are simpler: eight fixed DNS names, no wildcard TLS.

## Migration / future considerations

Custom domains per tenant; multi-workspace accounts; `status.shp…` public status page; `ws.shp…` if websocket traffic needs its own host.
