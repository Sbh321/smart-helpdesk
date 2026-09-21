<?php

declare(strict_types=1);

namespace App\Modules\Automation\Http\Resources;

use App\Modules\Automation\Domain\Assignment\AgentCandidate;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AgentCandidate */
final class AgentCandidateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'agent_id' => $this->id,
            'agent_name' => $this->name,
            'open_tickets' => $this->openTickets,
            'capacity' => $this->capacity,
            'load' => round($this->load(), 4),
            'last_assigned_at' => $this->lastAssignedAt?->format(DateTimeInterface::ATOM),
        ];
    }
}
