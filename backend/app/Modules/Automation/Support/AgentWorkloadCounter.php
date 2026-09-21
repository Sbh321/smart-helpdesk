<?php

declare(strict_types=1);

namespace App\Modules\Automation\Support;

use App\Modules\Agents\Models\AgentProfile;
use Carbon\CarbonImmutable;

/**
 * The only writer of `agent_profiles.active_ticket_count` besides `agents:reconcile-workload`.
 *
 * Decision (M2-05): the stored counter is kept and used everywhere. AssignTicket and
 * UnassignTicket move it when the agent changes, AdjustWorkloadOnTicketLifecycle moves it when a
 * status change enters or leaves the workload statuses, eligibility reads it under a row lock,
 * and the nightly command repairs drift against the live ticket count.
 */
final readonly class AgentWorkloadCounter
{
    /**
     * @param  CarbonImmutable|null  $assignedAt  set when the slot is taken by a new assignment (round-robin tie-break)
     */
    public function increment(string $agentId, ?CarbonImmutable $assignedAt = null): void
    {
        $this->move($agentId, 1, $assignedAt);
    }

    public function decrement(string $agentId): void
    {
        $this->move($agentId, -1);
    }

    private function move(string $agentId, int $delta, ?CarbonImmutable $assignedAt = null): void
    {
        // The row lock serialises concurrent moves; callers that already hold it re-enter for free.
        $agent = AgentProfile::query()->whereKey($agentId)->lockForUpdate()->first();
        if ($agent === null) {
            return;
        }

        $agent->active_ticket_count = max(0, $agent->active_ticket_count + $delta);
        if ($assignedAt !== null) {
            $agent->last_assigned_at = $assignedAt;
        }
        $agent->timestamps = false; // a counter move is not a profile edit
        $agent->save();
    }
}
