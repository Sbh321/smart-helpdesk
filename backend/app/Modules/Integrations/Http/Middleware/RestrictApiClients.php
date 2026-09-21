<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Http\Middleware;

use App\Modules\Integrations\Exceptions\RouteNotAvailableToClients;
use App\Modules\Integrations\Models\ApiClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs in the `tenant` group right after membership: an API client may only call routes marked
 * with `AllowApiClients`. Scopes decide *what* a client may do; this decides *where*, so a scope
 * that maps to `integrations.manage` (webhooks) can never reach `/v1/api-clients`, and user-level
 * routes such as `/v1/me` never see a client.
 */
final class RestrictApiClients
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() instanceof ApiClient && ! self::allowsClients($request->route())) {
            throw RouteNotAvailableToClients::make();
        }

        return $next($request);
    }

    public static function allowsClients(mixed $route): bool
    {
        if (! $route instanceof Route) {
            return false;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if ($middleware === AllowApiClients::ALIAS || $middleware === AllowApiClients::class) {
                return true;
            }
        }

        return false;
    }
}
