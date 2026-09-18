<?php

declare(strict_types=1);

namespace App\Modules\Identity\Session;

use Illuminate\Session\DatabaseSessionHandler;

/**
 * Adds `tenant_id` and `guard` to the session row (docs/08-database/entities.md §sessions), so an
 * operator can see which workspace a session belongs to and revoke sessions per tenant. The
 * resolver itself reads the tenant from the session payload, not from this column.
 */
final class TenantAwareDatabaseSessionHandler extends DatabaseSessionHandler
{
    protected function addUserInformation(&$payload): static
    {
        parent::addUserInformation($payload);

        $payload['tenant_id'] = tenant()?->getTenantKey();
        $payload['guard'] = $this->container?->bound('auth') && $this->container->make('auth')->hasUser()
            ? 'web'
            : null;

        return $this;
    }
}
