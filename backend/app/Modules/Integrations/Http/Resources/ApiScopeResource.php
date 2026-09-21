<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Http\Resources;

use App\Modules\Integrations\Domain\ScopeDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One scope an API client can be given, with the permissions it grants.
 *
 * @mixin ScopeDefinition
 */
final class ApiScopeResource extends JsonResource
{
    /**
     * @return array{scope: string, description: string, permissions: list<string>}
     */
    public function toArray(Request $request): array
    {
        /** @var ScopeDefinition $definition */
        $definition = $this->resource;

        return [
            'scope' => $definition->scope,
            'description' => $definition->description,
            'permissions' => $definition->permissions,
        ];
    }
}
