<?php

declare(strict_types=1);

namespace App\Modules\Platform\Actions;

use App\Modules\Audit\Audit;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Time\Clock;

/**
 * Suspension and reactivation (docs/03-architecture/tenancy.md §Provisioning). Suspended tenants
 * are refused by EnsureTenantActive on every tenant route.
 */
final readonly class ChangeTenantStatus
{
    public function __construct(private Clock $clock) {}

    public function suspend(Tenant $tenant, ?string $reason = null): Tenant
    {
        if ($tenant->status !== TenantStatus::Suspended) {
            $tenant->forceFill([
                'status' => TenantStatus::Suspended,
                'suspended_at' => $this->clock->now(),
            ])->save();

            Audit::record('tenant.suspended', $tenant, array_filter(['reason' => $reason]), tenantId: null);
        }

        return $tenant;
    }

    public function reactivate(Tenant $tenant): Tenant
    {
        if ($tenant->status !== TenantStatus::Active) {
            $tenant->forceFill([
                'status' => TenantStatus::Active,
                'suspended_at' => null,
            ])->save();

            Audit::record('tenant.reactivated', $tenant, tenantId: null);
        }

        return $tenant;
    }
}
