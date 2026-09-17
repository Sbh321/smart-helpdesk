# ADR-0007 Authentication: Sanctum sessions for the SPA, Passport client credentials for integrations, spatie permissions

**Status:** Accepted (2026-09-17)

## Context

Needs: first-party SPA login, third-party API access for integrations, service accounts, tenant membership, roles and granular permissions. Laravel's docs recommend Sanctum for SPAs and Passport only when OAuth2 is genuinely required; Passport's password grant is discouraged. Research: [01-research/backend-ecosystem.md](../01-research/backend-ecosystem.md) §Authentication.

## Decision

1. **SPA: Laravel Sanctum SPA cookie authentication** (`statefulApi()`, `/sanctum/csrf-cookie`, session cookie scoped to the tenant host). Hand-written controllers: login, logout, me, accept-invitation (signed URL), forgot/reset password. Rate limiting 5/min per email+IP. Fortify rejected because its user lookup is global-email based while our emails are unique per tenant.
2. **Integrations: Laravel Passport 13, client-credentials grant only**, clients bound to a tenant (`oauth_clients.tenant_id`) with scopes named after permissions (`tickets:read`, `tickets:write`, `contacts:read`, `contacts:write`, `webhooks:manage`). Tokens 1 h; shared signing keys. Time-boxed to one day in milestone 3; **fallback**: Sanctum tokens on an `ApiClient` model with the same ability names, which keeps the API contract identical.
3. **Authorisation: spatie/laravel-permission 8** with `teams=true`, `team_foreign_key=tenant_id`; global default roles (Tenant Owner, Tenant Admin, Support Manager, Support Agent, Developer) and tenant custom roles. Code checks permissions only (`tickets.assign`), never roles. Platform Super Admin is a separate guard/user table on the central domain.
4. Permission naming: `resource.action` (`tickets.view`, `tickets.create`, `tickets.update`, `tickets.assign`, `tickets.resolve`, `tickets.close`, `tickets.reopen`, `tickets.delete`, `comments.internal`, `contacts.view`, `contacts.manage`, `agents.view`, `agents.manage`, `teams.manage`, `sla.manage`, `settings.manage`, `users.manage`, `roles.manage`, `integrations.manage`, `reports.view`, `audit.view`). Wildcards disabled.

## Alternatives considered

- Passport for the SPA too (`CreateFreshApiToken` or password grant): relies on sessions anyway or on a discouraged grant. Rejected.
- Sanctum only (tokens for integrations): simplest; kept as the fallback, but OAuth2 client credentials is the standard integrators expect and the brief asks for it.
- Authorization-code + PKCE for third-party apps acting for users: V1.
- API keys: redundant with client credentials. Rejected.
- SSO/SAML/MFA: V1.

## Consequences

Two guards on disjoint routes (`auth:sanctum` for `/v1` from the SPA; `auth:api` for bearer clients on the same routes via a multi-guard middleware) with one permission vocabulary. Passport adds five tables, key management and a settings UI.

## Migration / future considerations

Authorization-code flow and personal access tokens later reuse the same scopes; passkeys/2FA via Fortify or Passport device grant when needed.
