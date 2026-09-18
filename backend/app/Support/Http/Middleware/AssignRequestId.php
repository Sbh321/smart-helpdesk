<?php

declare(strict_types=1);

namespace App\Support\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request a correlation id (docs/11-operations/logs.md §Request-id propagation).
 *
 * Caddy sets X-Request-Id; the app generates one when the header is missing or malformed.
 * The id goes into the log context (and from there into queued jobs), the problem-details
 * body and the response header. On terminate, one `request.completed` line is logged.
 */
final class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    private const PATTERN = '/^[A-Za-z0-9._:-]{8,64}$/';

    /** Paths polled by health checks; logging them would drown the useful lines. */
    private const QUIET_PATHS = ['up'];

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->headers->get(self::HEADER, '');
        $requestId = preg_match(self::PATTERN, $incoming) === 1 ? $incoming : (string) Str::ulid();

        $request->attributes->set('request_id', $requestId);
        $request->attributes->set('request_started_at', hrtime(true));
        Context::add('request_id', $requestId);

        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        if ($request->is(...self::QUIET_PATHS)) {
            return;
        }

        $startedAt = $request->attributes->get('request_started_at');

        Log::info('request.completed', [
            'method' => $request->method(),
            'route' => $request->route()?->getName() ?? $request->route()?->uri(),
            'status' => $response->getStatusCode(),
            'duration_ms' => is_int($startedAt) ? intdiv(hrtime(true) - $startedAt, 1_000_000) : null,
        ]);
    }
}
