<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\Bootstrappers\RlsTenancyBootstrapper;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * The authenticated principal (a user or an API client) must belong to the resolved tenant. A mismatch means a tampered
 * or stale session: it is destroyed, logged at critical level and answered with 401.
 */
final class EnsureTenantMembership
{
    public function __construct(private readonly RlsTenancyBootstrapper $bootstrapper) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $tenantId = tenant()?->getTenantKey();

        if ($user === null) {
            return $next($request);
        }

        $userTenantId = $user->getAttribute('tenant_id');

        if ($tenantId === null || $userTenantId === null || (string) $userTenantId !== (string) $tenantId) {
            Log::critical('tenancy.membership_mismatch', [
                'resolved_tenant_id' => $tenantId,
                'user_tenant_id' => $userTenantId,
            ]);

            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
            }

            throw new AuthenticationException;
        }

        // Change capture records who acted; token guards fire no Authenticated event.
        $this->bootstrapper->refreshActor();

        return $next($request);
    }
}
