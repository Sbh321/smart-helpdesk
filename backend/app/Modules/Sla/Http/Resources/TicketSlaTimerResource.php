<?php

declare(strict_types=1);

namespace App\Modules\Sla\Http\Resources;

use App\Modules\Sla\Models\TicketSlaTimer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An SLA timer of a ticket (first response or resolution) with its state and due times.
 *
 * @mixin TicketSlaTimer
 */
final class TicketSlaTimerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'cycle' => $this->cycle,
            'state' => $this->state,
            'target_minutes' => $this->target_minutes,
            'started_at' => $this->started_at,
            'warning_at' => $this->warning_at,
            'due_at' => $this->due_at,
            'paused_at' => $this->paused_at,
            'paused_total_seconds' => $this->paused_total_seconds,
            'warned_at' => $this->warned_at,
            'breached_at' => $this->breached_at,
            'met_at' => $this->met_at,
            'cancelled_at' => $this->cancelled_at,
            'policy_id' => $this->policy_id,
            'policy_version' => $this->policy_version,
            'warning_fraction' => (float) $this->warning_fraction,
            'calendar_id' => $this->calendar_id,
            'strategy' => $this->strategy,
            'strategy_version' => $this->strategy_version,
        ];
    }
}
