<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in user, as `/me` embeds it.
 *
 * @mixin User
 */
final class UserResource extends JsonResource
{
    /**
     * @return array{id: string, name: string, email: string, is_active: bool, preferences: array<string, mixed>, last_login_at: ?string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_active' => $this->is_active,
            'preferences' => $this->preferences ?? [],
            'last_login_at' => $this->resource->last_login_at?->toIso8601String(),
        ];
    }
}
