<?php

declare(strict_types=1);

namespace Database\Factories\Concerns;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Every factory of a tenant-scoped model uses this trait (docs/10-quality/testing.md §Conventions).
 *
 * Inside tenancy `BelongsToTenant` fills `tenant_id` by itself; outside it the caller must say
 * which tenant the row belongs to, and a factory that says neither fails on the NOT NULL column.
 * The isolation suite seeds two tenants through `forTenant()`, so a new factory without this trait
 * fails `tests/Isolation/ModelDataIsolationTest.php` as soon as its model is classified as primary.
 *
 * Row-level security (M3-07) only accepts a row of the tenant the connection is set to, so rows are
 * stored inside their own tenant's context: a factory row for tenant B made while A (or no tenant)
 * is current is saved in `$tenantB->run()`, which puts the previous context back afterwards. This is
 * the same initialisation the application uses; nothing here steps around the policies.
 *
 * @phpstan-require-extends Factory
 */
trait ForTenant
{
    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn (): array => ['tenant_id' => $tenant->getKey()]);
    }

    protected function store(Collection $results): void
    {
        $current = tenant()?->getTenantKey();

        foreach ($results->groupBy(fn (Model $model): string => (string) $model->getAttribute('tenant_id')) as $tenantId => $models) {
            $tenant = $tenantId === '' || $tenantId === $current ? null : Tenant::query()->find($tenantId);

            if ($tenant === null) {
                // The current tenant's rows, or no tenant at all (the insert then fails as it should).
                parent::store($models);

                continue;
            }

            $tenant->run(fn () => parent::store($models));
        }
    }
}
