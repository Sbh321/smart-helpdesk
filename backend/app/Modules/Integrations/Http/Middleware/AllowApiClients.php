<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marks a route as open to API clients (the **C** column of docs/07-api/conventions.md).
 * It does nothing itself; `RestrictApiClients` refuses clients on every route without it, so a
 * new endpoint is closed to integrations until someone decides otherwise.
 */
final class AllowApiClients
{
    public const ALIAS = 'api-clients';

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
