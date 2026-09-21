<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Actions;

use App\Modules\Audit\Audit;
use App\Modules\Integrations\Models\AccessToken;
use App\Modules\Integrations\Models\ApiClient;
use App\Support\Time\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Revokes a client and every token it holds. The guard refuses revoked clients on the next
 * request, so there is no grace period (docs/07-api/authentication.md §3). Revoking twice is a
 * no-op that writes no second audit entry.
 */
final readonly class RevokeApiClient
{
    public function __construct(private Clock $clock) {}

    public function __invoke(ApiClient $client): ApiClient
    {
        if ($client->revoked) {
            return $client;
        }

        return DB::transaction(function () use ($client): ApiClient {
            $client->forceFill(['revoked' => true, 'revoked_at' => $this->clock->now()])->save();

            AccessToken::query()
                ->where('tenant_id', $client->tenant_id)
                ->where('client_id', $client->id)
                ->where('revoked', false)
                ->update(['revoked' => true]);

            Audit::record('api_client.revoked', $client, ['name' => $client->name]);

            return $client;
        });
    }
}
