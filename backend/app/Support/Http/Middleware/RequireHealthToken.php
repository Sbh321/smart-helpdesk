<?php

declare(strict_types=1);

namespace App\Support\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protects the dependency health report (docs/11-operations/observability.md).
 *
 * Callers send `Authorization: Bearer <HEALTH_TOKEN>` or `X-Health-Token`. Without a configured
 * token the report is open only in the local environment. Platform admins get access in M1-07.
 */
final class RequireHealthToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('helpdesk.health.token');
        $given = (string) ($request->bearerToken() ?? $request->headers->get('X-Health-Token', ''));

        $allowed = $expected === ''
            ? app()->environment('local')
            : $given !== '' && hash_equals($expected, $given);

        abort_unless($allowed, 403, 'A valid health token is required.');

        return $next($request);
    }
}
