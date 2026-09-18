<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Http\Resources;

use App\Modules\Contacts\Http\Resources\TagResource;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A ticket. `contact`, `organization`, `category` and `tags` appear when requested with
 * `include=` (docs/07-api/pagination-filtering.md §Includes).
 *
 * @mixin Ticket
 */
final class TicketResource extends JsonResource
{
    // No @return shape: Scramble infers the exact keys and types from the array below.
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'impact' => $this->impact,
            'urgency' => $this->urgency,
            'priority_score' => (float) $this->priority_score,
            'priority_level' => $this->effectivePriority(),
            'priority_computed_level' => $this->priority_level,
            'priority_overridden' => $this->priority_override_level !== null,
            'contact_id' => $this->contact_id,
            'organization_id' => $this->organization_id,
            'category_id' => $this->category_id,
            'team_id' => $this->team_id,
            'assigned_agent_id' => $this->assigned_agent_id,
            'duplicate_of_id' => $this->duplicate_of_id,
            'created_via' => $this->created_via,
            'contact' => $this->whenLoaded('contact', fn () => [
                'id' => $this->contact->id,
                'name' => $this->contact->name,
                'email' => $this->contact->email,
            ]),
            'organization' => $this->whenLoaded('organization', fn () => $this->organization === null ? null : [
                'id' => $this->organization->id,
                'name' => $this->organization->name,
                'tier' => $this->organization->tier,
            ]),
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ]),
            'tags' => TagResource::collection($this->whenLoaded('tags')),
            'resolved_at' => $this->resolved_at?->toIso8601ZuluString(),
            'closed_at' => $this->closed_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at->toIso8601ZuluString(),
            'updated_at' => $this->updated_at->toIso8601ZuluString(),
        ];
    }
}
