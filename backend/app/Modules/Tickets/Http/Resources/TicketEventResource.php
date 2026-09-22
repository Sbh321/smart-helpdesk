<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Http\Resources;

use App\Modules\Tickets\Models\TicketEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry of a ticket's timeline: what changed, who did it and when.
 *
 * @mixin TicketEvent
 */
final class TicketEventResource extends JsonResource
{
    // No @return shape: Scramble infers the exact keys and types from the array below.
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'actor_type' => $this->actor_type,
            'actor_id' => $this->actor_id,
            // Cast to object: an empty PHP array would encode as [] and break key lookups in clients.
            /** @var array<string, mixed> */
            'old_values' => (object) $this->old_values,
            /** @var array<string, mixed> */
            'new_values' => (object) $this->new_values,
            'note' => $this->note,
            'created_at' => $this->created_at->toIso8601ZuluString('microsecond'),
        ];
    }
}
