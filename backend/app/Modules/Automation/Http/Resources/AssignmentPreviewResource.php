<?php

declare(strict_types=1);

namespace App\Modules\Automation\Http\Resources;

use App\Modules\Automation\Domain\Assignment\AssignmentResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What the AssignmentStrategy would do for a ticket now: the chosen agent, the ranking and the excluded agents with reasons; nothing is saved.
 *
 * @mixin AssignmentResult
 */
final class AssignmentPreviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $explanation = $this->explanation((string) $request->route('ticket')->id);

        return [
            'strategy' => $explanation['strategy'],
            'strategy_version' => $explanation['strategy_version'],
            'ticket_id' => $explanation['ticket_id'],
            'agent_id' => $explanation['agent_id'],
            'outcome' => $explanation['outcome'],
            'ranking' => AgentCandidateResource::collection($this->ranking),
            'excluded' => $explanation['excluded'],
        ];
    }
}
