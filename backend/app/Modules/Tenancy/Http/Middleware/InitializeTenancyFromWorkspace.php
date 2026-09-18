<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Exceptions\InvalidCredentials;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pre-authentication routes (login, accept invitation, password reset) name the workspace in
 * the body or the signed link. Unknown and archived workspaces answer exactly like wrong
 * credentials; suspended ones reach EnsureTenantActive and get 403.
 */
final class InitializeTenancyFromWorkspace
{
    public function __construct(private readonly TenantResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->resolver->singleTenantMode()
            ? $this->resolver->singleTenant()
            : $this->fromWorkspace($request->input('workspace', $request->query('workspace')));

        if ($tenant === null || $tenant->status === TenantStatus::Archived) {
            throw InvalidCredentials::make();
        }

        tenancy()->initialize($tenant);

        return $next($request);
    }

    public function terminate(): void
    {
        tenancy()->end();
    }

    private function fromWorkspace(mixed $workspace): ?Tenant
    {
        return is_string($workspace) && preg_match('/^[a-z0-9-]{1,63}$/i', $workspace) === 1
            ? Tenant::findBySlug($workspace)
            : null;
    }
}
