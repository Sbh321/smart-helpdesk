<?php

declare(strict_types=1);

namespace App\Modules\Agents\Http\Resources;

use App\Modules\Agents\Models\AgentProfile;
use App\Modules\Agents\Models\Skill;
use App\Modules\Agents\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AgentProfile */
final class AgentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'is_active' => $this->user->is_active,
            ],
            'capacity' => $this->capacity,
            'availability' => $this->availability,
            'active_ticket_count' => $this->active_ticket_count,
            'last_assigned_at' => $this->last_assigned_at?->toIso8601ZuluString(),
            'skills' => $this->skills->map(fn (Skill $skill): array => [
                'id' => $skill->id,
                'name' => $skill->name,
                'slug' => $skill->slug,
                'level' => (int) $skill->pivot->getAttribute('level'),
            ])->values(),
            'teams' => $this->teams->map(fn (Team $team): array => ['id' => $team->id, 'name' => $team->name])->values(),
        ];
    }
}
