<?php

declare(strict_types=1);

use App\Modules\Identity\Support\PermissionCatalogue;
use App\Modules\Tenancy\Http\Middleware\InitializeTenancyFromWorkspace;
use App\Modules\Tenancy\Http\Middleware\ResolveTenantFromPrincipal;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;

/*
 * Every tenant API route is authenticated and carries a permission, except a short allow-list.
 * Adding a route without `can:` fails here, which is the point (ADR-0007, docs/10-quality/testing.md).
 */

/** Routes that are deliberately open or user-level rather than permission-gated. */
const PERMISSION_ALLOW_LIST = [
    'system.ping',
    'system.health',
    'auth.login',
    'auth.logout',
    'auth.invitations.accept',
    'auth.password.forgot',
    'auth.password.reset',
    'me.show',
    'me.preferences.update',
    // A user's own inbox: every query is limited to the signed-in user.
    'notifications.index',
    'notifications.read',
    'notifications.read-all',
    // WebSocket channel authorisation: each channel checks its own permission (Realtime\Support\Channels).
    'realtime.auth',
];

/** Routes that run before anyone is signed in. */
const PRE_AUTH_ROUTES = [
    'system.ping',
    'system.health',
    'auth.login',
    'auth.invitations.accept',
    'auth.password.forgot',
    'auth.password.reset',
];

/**
 * Middleware with groups and aliases resolved, exactly as the kernel runs them. The router turns
 * `can:tickets.view` into `Authorize:tickets.view` and `auth:sanctum` into `Authenticate:sanctum`.
 *
 * @return list<string>
 */
function routeMiddleware(Route $route): array
{
    return array_values(array_filter(Router::getFacadeRoot()->gatherRouteMiddleware($route), 'is_string'));
}

function routeName(Route $route): string
{
    return $route->getName() ?? $route->methods()[0].' '.$route->uri();
}

/**
 * @return list<string> the permissions a route requires
 */
function routePermissions(Route $route): array
{
    $permissions = [];

    foreach (routeMiddleware($route) as $middleware) {
        if (str_starts_with($middleware, Authorize::class.':')) {
            $permissions[] = explode(',', substr($middleware, strlen(Authorize::class) + 1))[0];
        }
    }

    return $permissions;
}

/**
 * @return list<Route>
 */
function tenantApiRoutes(): array
{
    return array_values(array_filter(
        Router::getRoutes()->getRoutes(),
        fn (Route $route): bool => str_starts_with($route->uri(), 'v1/') || $route->uri() === 'v1',
    ));
}

/**
 * @return list<Route>
 */
function platformRoutes(): array
{
    return array_values(array_filter(
        Router::getRoutes()->getRoutes(),
        fn (Route $route): bool => str_starts_with($route->uri(), 'platform-api'),
    ));
}

it('gives every tenant API route a permission or an allow-list entry', function (): void {
    $missing = [];

    foreach (tenantApiRoutes() as $route) {
        $name = routeName($route);

        if (routePermissions($route) === [] && ! in_array($name, PERMISSION_ALLOW_LIST, true)) {
            $missing[] = $name;
        }
    }

    expect($missing)->toBe([], 'Add can:<permission> to these routes, or list them in PERMISSION_ALLOW_LIST.');
});

it('authenticates every tenant API route that is not pre-authentication', function (): void {
    $unauthenticated = [];

    foreach (tenantApiRoutes() as $route) {
        $name = routeName($route);
        $authenticated = collect(routeMiddleware($route))
            ->contains(fn (string $middleware): bool => str_starts_with($middleware, Authenticate::class));

        if (! $authenticated && ! in_array($name, PRE_AUTH_ROUTES, true)) {
            $unauthenticated[] = $name;
        }
    }

    expect($unauthenticated)->toBe([]);
});

it('guards every platform route with the platform guard', function (): void {
    $open = ['platform.auth.login', 'platform.csrf-cookie'];
    $unguarded = [];

    foreach (platformRoutes() as $route) {
        $name = routeName($route);
        $guarded = in_array(Authenticate::class.':platform', routeMiddleware($route), true);

        if (! $guarded && ! in_array($name, $open, true)) {
            $unguarded[] = $name;
        }
    }

    expect($unguarded)->toBe([]);
});

it('only uses permissions that exist in the catalogue', function (): void {
    $unknown = [];

    foreach (tenantApiRoutes() as $route) {
        foreach (routePermissions($route) as $permission) {
            if (! PermissionCatalogue::exists($permission)) {
                $unknown[] = $permission;
            }
        }
    }

    expect($unknown)->toBe([]);
});

it('never exposes a tenant route without tenant resolution', function (): void {
    $unresolved = [];

    foreach (tenantApiRoutes() as $route) {
        $middleware = routeMiddleware($route);
        $resolves = collect($middleware)->contains(
            fn (string $name): bool => in_array($name, [
                ResolveTenantFromPrincipal::class,
                InitializeTenancyFromWorkspace::class,
            ], true),
        );

        // /v1/ping and /v1/health are system routes outside the tenant groups.
        if (! $resolves && ! in_array(routeName($route), ['system.ping', 'system.health'], true)) {
            $unresolved[] = routeName($route);
        }
    }

    expect($unresolved)->toBe([]);
});

it('requires reports.export on every export route', function (): void {
    $routes = collect(tenantApiRoutes())->keyBy(fn (Route $route): string => routeName($route));

    foreach (['reports.exports.store', 'exports.tickets', 'exports.show'] as $name) {
        expect($routes)->toHaveKey($name)
            ->and(routePermissions($routes[$name]))->toContain('reports.export');
    }
    expect(routePermissions($routes['exports.tickets']))->toContain('tickets.view')
        ->and(routePermissions($routes['reports.exports.store']))->toContain('reports.view');
});
