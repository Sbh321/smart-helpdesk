# ADR-0024 Platform documentation and monitoring hosts behind the platform sign-in

**Status:** Accepted (2026-09-25). Extends the host layout of [ADR-0021](0021-host-layout-and-tenant-resolution.md) with a ninth host. Roadmap [M5-05, M5-06](../../roadmap/13-public-face.md).

## Context

The engineering documentation in `docs/` is now built as a site (M5-05). The owner wants it on its own host, `platform-docs.shp.subhambhandari.com.np`, readable only by a signed-in Platform Super Admin, and opened from a button in the platform console. The platform session cookie is host-only on the admin host (ADR-0021, security model), so the new host cannot see it; widening that cookie to the platform domain would send the platform session to every tenant host, which ADR-0021 rules out.

The content itself is not secret: the repository is public and holds no secret values (the environments page names where credentials live, not the credentials). The gate keeps operator material off a public site and out of search engines.

## Decision

1. **Host.** `platform-docs.<domain>` serves the built `docs/` site from the proxy image (`/srv/platform-docs`), with `X-Robots-Tag: noindex` and a `noindex` meta tag. Split layout only.
2. **Hand-off.** A signed-in admin's console calls `POST /platform-api/docs/handoff {next}` and follows the returned link: `https://platform-docs.<domain>/_session/start?admin&expires&nonce&next&signature`, an HMAC-SHA256 over those fields with the application key, valid for 60 seconds and single use (the nonce is remembered in the cache).
3. **Pass.** `/_session/start` exchanges a valid link for a cookie `shp_platform_docs`: host-only, `Secure`, `HttpOnly`, `SameSite=Lax`, encrypted by Laravel with the application key, holding the admin id and an expiry (8 hours), and redirects to `next` (same-host paths only).
4. **Check.** The proxy guards every other request with `forward_auth` to `/_session/check`, which answers 204 when the pass is valid and the admin still exists, and otherwise redirects to `https://admin.<domain>/platform/docs?next=<requested path>`; that console page requires the platform sign-in, then hands off again, so a guest lands on the page they asked for.
5. **No session, no tenant.** The platform-docs routes run with cookie encryption only: no session store, no tenancy, no CSRF (they are `GET`s that change nothing but the pass cookie).

## Amendment (2026-09-25): monitoring and revocation

- The **monitor host** uses the same pass instead of HTTP basic auth (development had no gate at all): the console's *Monitoring* button hands over to `monitor.<domain>`, Caddy guards every path except `/_session/*` with `forward_auth`, Horizon's gate and Telescope's `auth` check the pass themselves (in every environment, local included), and `/health` on that host is the health dashboard.
- The pass is a random token held by the server (cache, eight hours), not a signed admin id: the console session records the tokens it handed out and **console sign-out deletes them**, so signing out ends access to both hosts at once. Hand-off links are signed per host, so a link for one host does not work on the other. Endpoint: `POST /platform-api/handoff {target: docs | monitor, next}`; cookie `shp_platform_pass`.

## Consequences

- One more DNS record and certificate (`platform-docs` in the OpenTofu host lists, the on-demand TLS allow list).
- Console sign-out, expiry (eight hours) or deleting the admin ends a pass on the next request. There is no disabled state for platform admins today.
- Single-host installs do not serve the platform documentation. MVP-SHORTCUT → V1-PL-17 (a path on the one host).
- The proxy image is built with `docs/` as a second build context (`docs`).

## Alternatives considered

- **Widen the platform cookie to the platform domain.** Rejected: the platform session would reach tenant hosts.
- **Serve the docs under the admin host** (`admin.<domain>/docs/`), where the platform cookie already is. Simplest, but the owner asked for its own host.
- **HTTP basic auth** like `monitor`. Rejected by the owner: access should follow the platform sign-in, not a shared password.
