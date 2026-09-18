<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform routes never run inside a tenant (docs/03-architecture/tenancy.md §Planes).
 */
final class EnsureCentralContext
{
    public function handle(Request $request, Closure $next): Response
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        return $next($request);
    }
}
