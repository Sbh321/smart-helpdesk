<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform sessions use their own cookie, host-only on the admin host, so a tenant session can
 * never authenticate platform routes and the reverse (ADR-0021 §Cookies).
 *
 * Runs before StartSession, which reads these values.
 */
final class UsePlatformSession
{
    public function handle(Request $request, Closure $next): Response
    {
        config([
            'session.cookie' => config('helpdesk.platform.session_cookie'),
            // Host-only: no leading dot, so the cookie is not sent to app or api.
            'session.domain' => null,
        ]);

        return $next($request);
    }
}
