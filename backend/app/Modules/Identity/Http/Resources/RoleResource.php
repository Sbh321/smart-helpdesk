<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A Role and its permissions; global default roles cannot be edited.
 *
 * @mixin Role
 */
final class RoleResource extends JsonResource
{
    /**
     * @return array{id: string, name: string, is_global: bool, is_system: bool, permissions: list<string>}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_global' => $this->isGlobal(),
            'is_system' => (bool) $this->is_system,
            /** @var list<string> */
            'permissions' => $this->permissions->pluck('name')->values()->all(),
        ];
    }
}
