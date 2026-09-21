<?php

declare(strict_types=1);

namespace App\Modules\Automation\Http\Resources;

use App\Modules\Tickets\Models\TicketAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the assignment history with the strategy's explanation
 * (docs/05-algorithms/agent-assignment.md §Result).
 *
 * @mixin TicketAssignment
 *
 * @property-read string|null $team_id
 * @property-read string|null $agent_profile_id
 * @property-read string|null $previous_agent_profile_id
 * @property-read string|null $assigned_by_user_id
 * @property-read CarbonImmutable $created_at
 */
final class TicketAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reason' => $this->reason,
            'team_id' => $this->team_id,
            'agent_id' => $this->agent_profile_id,
            'previous_agent_id' => $this->previous_agent_profile_id,
            'assigned_by_user_id' => $this->assigned_by_user_id,
            'explanation' => $this->explanation,
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
