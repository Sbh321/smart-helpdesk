<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Audit\Audit;
use App\Modules\Platform\Actions\ChangeTenantStatus;
use App\Modules\Platform\Actions\ProvisionTenant;
use App\Modules\Platform\Http\Requests\StoreTenantRequest;
use App\Modules\Platform\Http\Requests\UpdateTenantRequest;
use App\Modules\Platform\Http\Resources\TenantResource;
use App\Modules\Tenancy\Models\Tenant;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Workspace administration for platform super admins (docs/07-api/conventions.md §Platform).
 * These routes are served only on the admin host and never run inside a tenant.
 */
final class TenantController
{
    /**
     * List workspaces.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $tenants = Tenant::query()
            ->when($request->string('status')->isNotEmpty(), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->string('search')->isNotEmpty(), function ($query) use ($request): void {
                $search = '%'.$request->string('search')->lower().'%';
                $query->where(fn ($q) => $q->whereRaw('lower(slug) like ?', [$search])->orWhereRaw('lower(name) like ?', [$search]));
            })
            ->orderBy('created_at')
            ->paginate(perPage: 25);

        return TenantResource::collection($tenants);
    }

    /**
     * Provision a workspace.
     *
     * Creates the tenant, its counter and primary domain, and invites the owner by email.
     * Running it again for the same slug fills in only what is missing.
     */
    #[Response(status: 201, type: TenantResource::class)]
    public function store(StoreTenantRequest $request, ProvisionTenant $provision): JsonResponse
    {
        $result = $provision(
            (string) $request->validated('slug'),
            (string) $request->validated('name'),
            (string) $request->validated('owner_email'),
            $request->validated('owner_name'),
            (string) $request->validated('timezone', 'UTC'),
        );

        return (new TenantResource($result['tenant']))->response()->setStatusCode(201);
    }

    /**
     * Show one workspace.
     */
    public function show(Tenant $tenant): TenantResource
    {
        return new TenantResource($tenant);
    }

    /**
     * Update workspace details.
     */
    public function update(UpdateTenantRequest $request, Tenant $tenant): TenantResource
    {
        $changes = $request->validated();
        $before = array_intersect_key($tenant->getOriginal(), $changes);

        $tenant->forceFill($changes)->save();

        if ($changes !== []) {
            Audit::record('tenant.updated', $tenant, ['before' => $before, 'after' => $changes], tenantId: null);
        }

        return new TenantResource($tenant);
    }

    /**
     * Suspend a workspace. Its users are refused with 403 tenant_suspended.
     */
    public function suspend(Request $request, Tenant $tenant, ChangeTenantStatus $status): TenantResource
    {
        return new TenantResource($status->suspend($tenant, $request->string('reason')->value() ?: null));
    }

    /**
     * Reactivate a suspended workspace.
     */
    public function reactivate(Tenant $tenant, ChangeTenantStatus $status): TenantResource
    {
        return new TenantResource($status->reactivate($tenant));
    }
}
