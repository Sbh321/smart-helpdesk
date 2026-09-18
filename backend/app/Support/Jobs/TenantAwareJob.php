<?php

declare(strict_types=1);

namespace App\Support\Jobs;

/**
 * Conventions for queued jobs that run inside a tenant (docs/11-operations/queues.md).
 *
 * Tenant context itself is serialised and restored by stancl/tenancy's queue bootstrapper
 * (configured in roadmap task M1-06); this trait adds the Horizon tag and explicit retry settings.
 */
trait TenantAwareJob
{
    public int $tries = 3;

    public int $timeout = 60;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        $tenant = function_exists('tenant') ? tenant('id') : null;

        return $tenant !== null ? ['tenant:'.$tenant] : ['tenant:central'];
    }
}
