<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Http\Resources;

use App\Modules\Agents\Http\Resources\SkillResource;
use App\Modules\Tickets\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Category
 */
final class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'default_team' => $this->defaultTeam === null ? null : [
                'id' => $this->defaultTeam->id,
                'name' => $this->defaultTeam->name,
            ],
            'required_skills' => SkillResource::collection($this->skills),
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
        ];
    }
}
