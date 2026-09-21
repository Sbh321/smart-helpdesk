<?php

declare(strict_types=1);

namespace App\Modules\Agents\Http\Resources;

use App\Modules\Agents\Models\AgentProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The agent profile of the signed-in user, as `/me` embeds it.
 *
 * @mixin AgentProfile
 */
final class AgentSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'capacity' => $this->capacity,
            'availability' => $this->availability,
            'active_ticket_count' => $this->active_ticket_count,
        ];
    }
}
