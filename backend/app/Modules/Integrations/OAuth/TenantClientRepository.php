<?php

declare(strict_types=1);

namespace App\Modules\Integrations\OAuth;

use App\Modules\Integrations\Models\ApiClient;
use App\Modules\Tenancy\Models\Tenant;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;

/**
 * Passport's client lookup, adapted:
 *  - no per-process memoisation (Passport wraps `find()` in `once()`), so a revocation is seen by
 *    the very next request even in a long-lived worker;
 *  - a client counts as active only while its workspace is active, so a suspended workspace
 *    cannot obtain tokens.
 */
final class TenantClientRepository extends ClientRepository
{
    public function find(string|int $id): ?Client
    {
        if (! is_string($id) || preg_match('/^[0-9a-f-]{36}$/i', $id) !== 1) {
            return null;
        }

        return ApiClient::query()->withoutGlobalScopes()->find($id);
    }

    public function findActive(string|int $id): ?Client
    {
        $client = $this->find($id);

        if (! $client instanceof ApiClient || $client->revoked) {
            return null;
        }

        $tenant = Tenant::query()->find($client->tenant_id);

        return $tenant instanceof Tenant && $tenant->isActive() ? $client : null;
    }
}
