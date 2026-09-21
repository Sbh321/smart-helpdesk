<?php

declare(strict_types=1);

namespace App\Modules\Agents\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A workspace user who has no agent profile yet and can be made an Agent.
 *
 * @mixin User
 */
final class AvailableUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
        ];
    }
}
