<?php

declare(strict_types=1);

use App\Support\ApiDocs\RedirectResponseToSchema;
use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;

/*
 * OpenAPI generation with Scramble (ADR-0010, docs/07-api/documentation.md).
 * Security schemes, the token endpoint, the shared problem-details schema, per-route access and the
 * `viewApiDocs` gate live in App\Providers\ApiDocsServiceProvider and App\Support\ApiDocs.
 */

return [

    // Tenant API routes. /v1/health is an operator endpoint (token-protected), not part of the public reference.
    'api_path' => [
        'include' => 'v1',
        'exclude' => ['v1/health'],
    ],

    // Routes are host-agnostic in development and single-host mode (ADR-0021), so no domain filter.
    'api_domain' => null,

    // `php artisan scramble:export` writes here (relative to backend/); the file is committed.
    'export_path' => 'openapi.json',

    'cache' => [
        'key' => 'scramble.openapi',
        'store' => 'file',
    ],

    'info' => [
        'version' => env('API_VERSION', '1.0.0'),
        'description' => <<<'MD'
            Smart Helpdesk REST API. All endpoints live under `/v1` on the API host; the token endpoint is
            `POST /oauth/token` on the same host. The examples below use
            `API=https://api.shp.subhambhandari.com.np` (development: `https://api.shp.localhost`).

            ## Authentication

            There are two ways to call the API. Pick the one that matches who is calling.

            | | Session (cookie) | API client (bearer token) |
            |---|---|---|
            | For | the Smart Helpdesk web app and browser code on the app host | servers, scripts, monitoring tools, other systems |
            | Credentials | workspace, email and password of a user | client id and client secret from Settings → Developer → API clients |
            | Sent on every request as | the `shp_session` cookie, plus `X-XSRF-TOKEN` on POST/PUT/PATCH/DELETE | the header `Authorization: Bearer <access_token>` |
            | Lifetime | until logout or idle expiry | 1 hour, then request a new token |
            | Can call | every endpoint the user's permissions allow | only endpoints marked *Open to API clients*, within the token's scopes |
            | Security scheme below | `session` | `oauth2` |

            The client id and secret are **never** sent to `/v1` endpoints. They are exchanged once for an access
            token, and only that token goes in the `Authorization` header. OpenAPI lists this header under each
            operation's security (the lock badge and the *Auth* field of *Try it*), not in the headers table.

            ### API client: step by step

            **1. Create a client.** A workspace user with `integrations.manage` opens Settings → Developer → API
            clients, names the client and ticks its scopes. The secret is shown **once**; store it like a password.

            **2. Request an access token** (form-encoded, no `Authorization` header):

            ```bash
            curl -X POST "$API/oauth/token" \
              -H "Accept: application/json" \
              -d grant_type=client_credentials \
              -d client_id=CLIENT_ID \
              -d client_secret=CLIENT_SECRET \
              -d "scope=tickets:read tickets:write"
            ```

            ```json
            { "token_type": "Bearer", "expires_in": 3600, "access_token": "ACCESS_TOKEN" }
            ```

            `scope` is optional (space-separated); leaving it out grants every scope the client has. Asking for a
            scope the client was not given answers `400 invalid_scope`; a wrong id or secret, a revoked client or
            a suspended workspace answers `401 invalid_client`. At most 10 token requests a minute per client.

            **3. Call the API with the token** (below in the shell variable `ACCESS_TOKEN`) on every request:

            ```bash
            curl -g "$API/v1/tickets?filter[status]=open" \
              -H "Accept: application/json" \
              -H "Authorization: Bearer $ACCESS_TOKEN"

            curl -X POST "$API/v1/tickets" \
              -H "Accept: application/json" \
              -H "Content-Type: application/json" \
              -H "Authorization: Bearer $ACCESS_TOKEN" \
              -H "Idempotency-Key: order-1042" \
              -d '{"title": "Printer offline", "description": "Floor 2 printer shows error E-05.",
                   "contact_id": "CONTACT_UUID", "category_id": "CATEGORY_UUID", "impact": 2, "urgency": 3}'
            ```

            The contact and category ids come from `GET /v1/contacts` (`contacts:read`) and `GET /v1/categories`
            (`tickets:read`).

            **4. Renew.** There is no refresh token. When `expires_in` has passed, or a call answers
            `401 unauthenticated`, request a new token (step 2) and repeat the call. Reuse one token for many calls
            instead of requesting a token per call.

            Good to know:

            - The workspace comes from the client itself. There is no header or parameter to choose a workspace,
              so a leaked secret exposes one workspace, only within its scopes. Revoke it in Settings and create a
              new client; revocation takes effect on the next request.
            - Scopes grant permissions: `tickets:read` → `tickets.view`; `tickets:write` → `tickets.create`,
              `tickets.update`, `tickets.resolve`, `tickets.close`; `contacts:read` → `contacts.view`;
              `contacts:write` → `contacts.manage`; `catalog:read` → `agents.view`; `webhooks:manage` →
              `integrations.manage` (webhook endpoints only).
            - Each operation's description ends with *Open to API clients with scope `…`* when a token may call it.
              Any other operation, or a token without the scope, answers `403 forbidden`.
            - `POST /tickets` and `POST /contacts` accept an optional `Idempotency-Key` header (1–255 visible
              ASCII characters, kept 24 hours): repeating a request with the same key returns the first response
              instead of creating a second record.
            - 120 API calls a minute per client; over the limit answers `429` with `Retry-After`.
            - Actions are recorded in the ticket history and audit log as the API client, and tickets it creates
              have `created_via = api`.

            ### Session: step by step

            Used by the web app on the app host. Browser code on another origin cannot use it; use an API client.
            All requests must send cookies (`credentials: "include"` in `fetch`, `withCredentials` in axios).

            **1. Get the CSRF cookie:** `GET $API/sanctum/csrf-cookie` sets the `XSRF-TOKEN` cookie.

            **2. Log in:** `POST $API/v1/auth/login` with the header `X-XSRF-TOKEN` set to the URL-decoded value of
            the `XSRF-TOKEN` cookie and the body:

            ```json
            { "workspace": "acme", "email": "agent@example.com", "password": "…", "remember": false }
            ```

            The answer is the signed-in user (the same as `GET /v1/me`) and a `shp_session` cookie. The workspace
            is fixed by this login; nothing in later requests can change it.

            **3. Call the API.** The browser sends the cookies itself. Add `Accept: application/json` to every
            request and `X-XSRF-TOKEN` to every POST, PUT, PATCH and DELETE.

            **4. Log out:** `POST $API/v1/auth/logout`.

            A missing or expired session answers `401 unauthenticated` (log in again). A missing or stale CSRF
            token answers `419 session_expired` (repeat step 1, then the request).

            ## Conventions

            - Errors are RFC 9457 problem details (`application/problem+json`) with a stable `code` and a
              `request_id` that matches the `X-Request-Id` response header.
            - The tenant comes from the authenticated principal, never from the host or a header.
            - Guides: authentication and scopes (`docs/07-api/authentication.md`), webhooks and signature
              verification (`docs/07-api/webhooks.md`), errors (`docs/07-api/errors.md`), changes
              (`docs/07-api/CHANGELOG.md`).
            MD,
    ],

    'ui' => [
        'title' => 'Smart Helpdesk API',
    ],

    'dev_tools' => [
        'enabled' => env('SCRAMBLE_DEV_TOOLS', false),
    ],

    'renderer' => 'elements',

    'renderers' => [
        'elements' => [
            'view' => 'scramble::docs',
            'theme' => 'system',
            'hideTryIt' => false,
            'hideSchemas' => false,
            // The product mark (frontend/scripts/brand-icons.mjs writes it to public/).
            'logo' => '/favicon.svg',
            'tryItCredentialsPolicy' => 'include',
            'layout' => 'responsive',
            'router' => 'hash',
        ],
        'scalar' => [
            'view' => 'scramble::scalar',
            'cdn' => 'https://cdn.jsdelivr.net/npm/@scalar/api-reference',
            'theme' => 'laravel',
            'proxyUrl' => 'https://proxy.scalar.com',
            'darkMode' => false,
            'showDeveloperTools' => 'never',
            'agent' => ['disabled' => true],
            'credentials' => 'include',
        ],
    ],

    // The server (https://<helpdesk.hosts.api>/v1, ADR-0021) is set by ApiDocsServiceProvider; paths are relative to it.
    'servers' => null,

    'enum_cases_description_strategy' => 'description',

    'enum_cases_names_strategy' => false,

    'flatten_deep_query_parameters' => true,

    // Unused: Scramble's own routes are switched off (ApiDocsServiceProvider). The reference is served by
    // ApiDocsController behind the `viewApiDocs` gate (routes/web.php, Platform/Routes/platform.php); kept
    // restrictive in case the default routes are ever re-enabled.
    'middleware' => [
        'web',
        RestrictedDocsAccess::class,
    ],

    // Documents redirects (media downloads) as 302 + Location instead of `200 {}`.
    'extensions' => [
        RedirectResponseToSchema::class,
    ],

    // Security is set by the document transformer in ApiDocsServiceProvider.
    'security_strategy' => null,
];
