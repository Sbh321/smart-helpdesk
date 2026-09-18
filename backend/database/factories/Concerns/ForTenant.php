<?php

declare(strict_types=1);

namespace Database\Factories\Concerns;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Every factory of a tenant-scoped model uses this trait (docs/10-quality/testing.md §Conventions).
 *
 * Inside tenancy `BelongsToTenant` fills `tenant_id` by itself; outside it the caller must say
 * which tenant the row belongs to, and a factory that says neither fails on the NOT NULL column.
 * The isolation suite seeds two tenants through `forTenant()`, so a new factory without this trait
 * fails `tests/Isolation/ModelDataIsolationTest.php` as soon as its model is classified as primary.
 *
 * @phpstan-require-extends Factory
 */
trait ForTenant
{
    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn (): array => ['tenant_id' => $tenant->getKey()]);
    }
}
