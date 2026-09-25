<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Platform\Models\PlatformUser;
use App\Modules\Platform\Support\PlatformPass;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A deactivated admin is signed out on their next request, and their passes to the docs and monitor
 * hosts end with the session (ADR-0025 §7). Runs in the `platform` group after the session starts.
 */
final class EnsurePlatformAdminActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = Auth::guard('platform')->user();
        if ($admin instanceof PlatformUser && ! $admin->canSignIn()) {
            Auth::guard('platform')->logout();
            app(PlatformPass::class)->revokeFor($request->session());
            $request->session()->invalidate();

            throw new AuthenticationException('This platform admin account is deactivated.', ['platform']);
        }

        return $next($request);
    }
}
