<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Http\Resources;

use App\Modules\Integrations\Models\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The response to `POST /v1/api-clients`: the client plus its credentials. `client_secret` is
 * shown this one time and cannot be read again.
 *
 * @mixin ApiClient
 */
final class NewApiClientResource extends JsonResource
{
    /**
     * @return array{id: string, name: string, scopes: list<string>, revoked: bool, revoked_at: string|null, last_used_at: string|null, created_at: string, client_id: string, client_secret: string}
     */
    public function toArray(Request $request): array
    {
        /** @var ApiClient $client */
        $client = $this->resource;

        return [
            ...(new ApiClientResource($client))->toArray($request),
            'client_id' => $client->id,
            'client_secret' => (string) $client->plainSecret,
        ];
    }
}
