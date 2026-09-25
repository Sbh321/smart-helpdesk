<?php

declare(strict_types=1);

use App\Modules\Billing\Http\Middleware\EnsureSubscriptionWritable;
use App\Modules\Integrations\Http\Middleware\RestrictApiClients;
use App\Modules\Platform\Http\Middleware\EnsurePlatformAdminActive;
use App\Modules\Platform\Http\Middleware\UsePlatformSession;
use App\Modules\Platform\Http\Middleware\ValidatePlatformCsrfToken;
use App\Modules\Tenancy\Http\Middleware\EnsureCentralContext;
use App\Modules\Tenancy\Http\Middleware\EnsureTenantActive;
use App\Modules\Tenancy\Http\Middleware\EnsureTenantMembership;
use App\Modules\Tenancy\Http\Middleware\InitializeTenancyFromWorkspace;
use App\Modules\Tenancy\Http\Middleware\ResolveTenantFromPrincipal;
use App\Support\Errors\ProblemDetails;
use App\Support\Http\Middleware\AssignRequestId;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Session\Middleware\StartSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // The API is served at api.{PLATFORM_DOMAIN}/v1 (ADR-0021); single-host installs strip /api at the proxy.
        apiPrefix: 'v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Caddy is the only ingress and terminates TLS (docs/09-infrastructure/docker.md).
        $middleware->trustProxies(at: '*');
        // First, so every log line and error body of the request carries the id.
        $middleware->prepend(AssignRequestId::class);

        // SPA requests from app.<domain> are stateful (Sanctum); tokens are stateless.
        $middleware->statefulApi();

        // There is no server-rendered login page: the SPA owns it. Without this, a guest request
        // that does not ask for JSON would try to build a route named "login" and fail.
        $middleware->redirectGuestsTo(
            fn (): string => 'https://'.config('helpdesk.hosts.app').'/login',
        );

        // Tenant resolution groups (docs/03-architecture/tenancy.md §Tenant resolution).
        // `auth:sanctum,api`: SPA sessions (and Sanctum tokens) first, then API client bearer
        // tokens (docs/07-api/authentication.md §4). Clients only reach routes marked for them,
        // and are rate limited per client.
        $middleware->group('tenant', [
            ResolveTenantFromPrincipal::class,
            EnsureTenantActive::class,
            'auth:sanctum,api',
            EnsureTenantMembership::class,
            RestrictApiClients::class,
            // After the grace period an expired subscription makes the workspace read-only (ADR-0025 §6).
            EnsureSubscriptionWritable::class,
            'throttle:api-clients',
        ]);
        $middleware->group('tenant.guest', [
            InitializeTenancyFromWorkspace::class,
            EnsureTenantActive::class,
        ]);
        // Platform API: its own session cookie on the admin host, never inside a tenant.
        $middleware->group('platform', [
            EnsureCentralContext::class,
            UsePlatformSession::class,
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ValidatePlatformCsrfToken::class,
            // A deactivated admin is signed out on the next request (ADR-0025 §7).
            EnsurePlatformAdminActive::class,
        ]);

        // The platform cookie settings must be in place before the session starts. Without a priority, the
        // sorter moves EncryptCookies/StartSession/Authenticate ahead of it (the `api` group's
        // SubstituteBindings comes first), so the platform session used the tenant cookie.
        $middleware->prependToPriorityList(EncryptCookies::class, UsePlatformSession::class);

        // Tenancy before authentication, membership right after it, both before route bindings.
        $middleware->prependToPriorityList(AuthenticatesRequests::class, ResolveTenantFromPrincipal::class);
        $middleware->prependToPriorityList(AuthenticatesRequests::class, InitializeTenancyFromWorkspace::class);
        $middleware->prependToPriorityList(AuthenticatesRequests::class, EnsureTenantActive::class);
        $middleware->appendToPriorityList(AuthenticatesRequests::class, EnsureTenantMembership::class);
        $middleware->appendToPriorityList(EnsureTenantMembership::class, RestrictApiClients::class);
        $middleware->appendToPriorityList(RestrictApiClients::class, EnsureSubscriptionWritable::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // RFC 9457 problem details for the API hosts (docs/03-architecture/error-handling.md).
        $exceptions->shouldRenderJsonWhen(ProblemDetails::appliesTo(...));
        $exceptions->render(ProblemDetails::render(...));
    })->create();
