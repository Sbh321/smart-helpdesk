<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Platform\Support\PlatformPass;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pages the application itself serves on the monitor host (the health dashboard) also check the platform
 * pass, not only the proxy in front of them (ADR-0024).
 */
final readonly class RequirePlatformPass
{
    public function __construct(private PlatformPass $pass) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_if($this->pass->check($request->cookie(PlatformPass::cookieName())) === null, 403);

        return $next($request);
    }
}
