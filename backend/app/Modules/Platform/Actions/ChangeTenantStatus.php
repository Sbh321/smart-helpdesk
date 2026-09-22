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
            $old = $tenant->status->value;
            $tenant->forceFill([
                'status' => TenantStatus::Suspended,
                'suspended_at' => $this->clock->now(),
            ])->save();

            $changes = ['status' => ['old' => $old, 'new' => TenantStatus::Suspended->value]];
            Audit::record('tenant.suspended', $tenant, $reason === null ? $changes : [...$changes, 'reason' => $reason], tenantId: null);
        }

        return $tenant;
    }

    public function reactivate(Tenant $tenant): Tenant
    {
        if ($tenant->status !== TenantStatus::Active) {
            $old = $tenant->status->value;
            $tenant->forceFill([
                'status' => TenantStatus::Active,
                'suspended_at' => null,
            ])->save();

            Audit::record('tenant.reactivated', $tenant, ['status' => ['old' => $old, 'new' => TenantStatus::Active->value]], tenantId: null);
        }

        return $tenant;
    }
}
