<?php

declare(strict_types=1);

namespace App\Modules\Agents\Http\Resources;

use App\Modules\Agents\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A Team of agents.
 *
 * @mixin Team
 */
final class TeamResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'members' => AgentSummaryResource::collection($this->agents),
        ];
    }
}
