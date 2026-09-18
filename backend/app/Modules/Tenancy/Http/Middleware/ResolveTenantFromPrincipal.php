<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\Support\TenantResolver;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Initialises tenancy for authenticated tenant routes before the user is loaded
 * (docs/03-architecture/tenancy.md §Tenant resolution). The source is, in order:
 * single-tenant configuration, the session tenant set at login, the bearer token's tenant.
 * A request with none of them continues without tenancy and fails at `auth`.
 */
final class ResolveTenantFromPrincipal
{
    public function __construct(private readonly TenantResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->resolver->singleTenantMode()) {
            $tenant = $this->resolver->singleTenant();
            abort_if($tenant === null, 503, 'The configured single tenant does not exist.');
            tenancy()->initialize($tenant);

            return $next($request);
        }

        $tenantId = $this->resolver->sessionTenantId($request) ?? $this->resolver->bearerTenantId($request);

        if ($tenantId !== null) {
            $tenant = $this->resolver->find($tenantId);

            if ($tenant === null) {
                // The tenant was deleted while the session lived on.
                $request->session()->invalidate();

                throw new AuthenticationException;
            }

            tenancy()->initialize($tenant);
        }

        return $next($request);
    }

    public function terminate(): void
    {
        tenancy()->end();
    }
}
