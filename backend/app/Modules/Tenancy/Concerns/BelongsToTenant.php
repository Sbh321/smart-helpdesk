<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Concerns;

use LogicException;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant as StanclBelongsToTenant;

/**
 * Primary tenant-scoped models use this trait (docs/03-architecture/tenancy.md §Model rules).
 *
 * - Queries are filtered by the current tenant (stancl's TenantScope; RLS is the backstop).
 * - `tenant_id` is filled from the current tenant on create; creating outside a tenant without
 *   an explicit tenant_id fails on the NOT NULL column.
 * - `tenant_id` never changes after create (the database trigger enforces the same rule).
 *
 * Register the model's table in App\Modules\Tenancy\Support\TenantTables.
 */
trait BelongsToTenant
{
    use StanclBelongsToTenant {
        StanclBelongsToTenant::bootBelongsToTenant as bootStanclBelongsToTenant;
    }

    /**
     * Both traits share the basename, so Laravel calls this boot method once; it boots stancl's
     * scope and creating hook, then adds the immutability guard.
     */
    public static function bootBelongsToTenant(): void
    {
        static::bootStanclBelongsToTenant();

        static::updating(function ($model): void {
            if ($model->isDirty('tenant_id')) {
                throw new LogicException('tenant_id cannot change after a record is created.');
            }
        });
    }
}
