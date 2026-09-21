<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Http\Resources;

use App\Modules\Integrations\Models\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An API client as Settings → Developer lists it. The secret is never part of this shape.
 *
 * @mixin ApiClient
 */
class ApiClientResource extends JsonResource
{
    /**
     * @return array{id: string, name: string, scopes: list<string>, revoked: bool, revoked_at: string|null, last_used_at: string|null, created_at: string}
     */
    public function toArray(Request $request): array
    {
        /** @var ApiClient $client */
        $client = $this->resource;

        return [
            'id' => $client->id,
            'name' => $client->name,
            'scopes' => $client->scopes,
            'revoked' => $client->revoked,
            'revoked_at' => $client->revoked_at?->toIso8601ZuluString(),
            'last_used_at' => $client->last_used_at?->toIso8601ZuluString(),
            'created_at' => $client->created_at->toIso8601ZuluString(),
        ];
    }
}
