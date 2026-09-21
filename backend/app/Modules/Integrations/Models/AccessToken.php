<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Models;

use Laravel\Passport\Token;

/**
 * An access token issued by `POST /oauth/token`. The token endpoint runs before any tenant is
 * known, so `tenant_id` is copied from the client when the row is written, and the tenant
 * resolver reads it back to initialise tenancy for bearer requests.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $client_id
 * @property list<string>|null $scopes
 * @property bool $revoked
 */
final class AccessToken extends Token
{
    protected static function booted(): void
    {
        self::creating(function (AccessToken $token): void {
            if ($token->getAttribute('tenant_id') === null) {
                $token->setAttribute('tenant_id', ApiClient::query()
                    ->withoutGlobalScopes()
                    ->whereKey($token->client_id)
                    ->value('tenant_id'));
            }
        });
    }
}
