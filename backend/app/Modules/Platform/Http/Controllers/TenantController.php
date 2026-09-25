<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Audit\Audit;
use App\Modules\Billing\Enums\SubscriptionState;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Support\BillingSettings;
use App\Modules\Billing\Support\SubscriptionStateSql;
use App\Modules\Platform\Actions\ChangeTenantStatus;
use App\Modules\Platform\Actions\ProvisionTenant;
use App\Modules\Platform\Http\Requests\StoreTenantRequest;
use App\Modules\Platform\Http\Requests\UpdateTenantRequest;
use App\Modules\Platform\Http\Resources\TenantResource;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Time\Clock;
use Dedoc\Scramble\Attributes\QueryParameter;
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
    #[QueryParameter('status', 'active, suspended or archived.', type: 'string')]
    #[QueryParameter('subscription', 'trialing, active, grace, expired or none, comma separated.', type: 'string')]
    #[QueryParameter('search', 'Part of the name or address.', type: 'string')]
    public function index(Request $request, Clock $clock, BillingSettings $settings): AnonymousResourceCollection
    {
        $states = array_values(array_filter(array_map(
            fn (string $value): ?SubscriptionState => SubscriptionState::tryFrom(trim($value)),
            explode(',', $request->string('subscription')->value()),
        )));

        $query = Tenant::query()->select('tenants.*');
        if ($states !== []) {
            SubscriptionStateSql::joinOnto($query);
            SubscriptionStateSql::whereState($query, $states, $clock->now(), $settings->graceDays());
        }

        $tenants = $query
            ->when($request->string('status')->isNotEmpty(), fn ($query) => $query->where('tenants.status', $request->string('status')))
            ->when($request->string('search')->isNotEmpty(), function ($query) use ($request): void {
                $search = '%'.$request->string('search')->lower().'%';
                $query->where(fn ($q) => $q->whereRaw('lower(tenants.slug) like ?', [$search])->orWhereRaw('lower(tenants.name) like ?', [$search]));
            })
            ->orderByDesc('tenants.created_at')
            ->paginate(perPage: min(100, max(1, $request->integer('per_page', 25))));

        TenantResource::preload($tenants->items());

        return TenantResource::collection($tenants);
    }

    /**
     * Provision a workspace.
     *
     * Creates the tenant, its counter and primary domain, starts its subscription (the active trial plan
     * unless `plan_id` is given, then `periods` periods of it) and invites the owner by email.
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
            $request->filled('plan_id') ? Plan::query()->findOrFail($request->validated('plan_id')) : null,
            (int) $request->validated('periods', 1),
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
