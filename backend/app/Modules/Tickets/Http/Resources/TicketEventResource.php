<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Http\Resources;

use App\Modules\Tickets\Models\TicketEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
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
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'note' => $this->note,
            'created_at' => $this->created_at->toIso8601ZuluString('microsecond'),
        ];
    }
}
