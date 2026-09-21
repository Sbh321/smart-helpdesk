<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Actions;

use App\Models\User;
use App\Modules\Audit\Audit;
use App\Modules\Integrations\Models\ApiClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a client-credentials client in the current workspace (docs/07-api/authentication.md §3).
 * The secret is random, stored hashed by Passport, and only readable from the returned model's
 * `plainSecret` during this request.
 */
final readonly class CreateApiClient
{
    /**
     * @param  list<string>  $scopes
     */
    public function __invoke(string $name, array $scopes, ?User $actor): ApiClient
    {
        return DB::transaction(function () use ($name, $scopes, $actor): ApiClient {
            $client = new ApiClient;
            $client->forceFill([
                'name' => $name,
                'secret' => Str::random(48),
                'provider' => null,
                'redirect_uris' => [],
                'grant_types' => [ApiClient::GRANT],
                'scopes' => array_values(array_unique($scopes)),
                'revoked' => false,
                'created_by_user_id' => $actor?->getKey(),
            ])->save();

            Audit::record('api_client.created', $client, ['name' => $name, 'scopes' => $client->scopes]);

            return $client;
        });
    }
}
