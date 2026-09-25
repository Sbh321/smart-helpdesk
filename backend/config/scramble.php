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
            `POST /oauth/token` on the same host.

            - Authentication: the SPA uses the Sanctum session cookie (`GET /sanctum/csrf-cookie` first, then send
              `X-XSRF-TOKEN`). API clients use OAuth 2.0 client credentials: `POST /oauth/token` returns a bearer
              token for one hour. Each operation says whether API clients may call it and with which scope;
              the others answer a client with `403 forbidden`.
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
