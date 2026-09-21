<?php

declare(strict_types=1);

namespace App\Modules\Automation\Queries;

use App\Modules\Agents\Models\AgentProfile;
use App\Modules\Tickets\Domain\Priority;
use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Models\Ticket;

/**
 * Live workload of an agent, counted from tickets (docs/04-domain/agents-and-teams.md §Workload
 * definition). The stored `agent_profiles.active_ticket_count` is the fast copy of `liveCounts()`.
 */
final readonly class AgentWorkload
{
    /**
     * @return array{active_ticket_count: int, capacity: int, load: float, by_priority: array{P1: int, P2: int, P3: int, P4: int}}
     */
    public function for(AgentProfile $agent): array
    {
        $counts = Ticket::query()
            ->where('assigned_agent_id', $agent->id)
            ->whereIn('status', self::statuses())
            ->selectRaw('COALESCE(priority_override_level, priority_level) AS effective_priority, COUNT(*) AS aggregate')
            ->groupByRaw('COALESCE(priority_override_level, priority_level)')
            ->pluck('aggregate', 'effective_priority');

        $byPriority = ['P1' => 0, 'P2' => 0, 'P3' => 0, 'P4' => 0];
        foreach (Priority::cases() as $priority) {
            $byPriority[$priority->value] = (int) ($counts[$priority->value] ?? 0);
        }

        $active = array_sum($byPriority);

        return [
            'active_ticket_count' => $active,
            'capacity' => $agent->capacity,
            'load' => $agent->capacity === 0 ? 1.0 : $active / $agent->capacity,
            'by_priority' => $byPriority,
        ];
    }

    /**
     * Live counts for every agent of the current tenant that has at least one active ticket.
     *
     * @return array<string, int>
     */
    public function liveCounts(): array
    {
        return Ticket::query()
            ->whereNotNull('assigned_agent_id')
            ->whereIn('status', self::statuses())
            ->selectRaw('assigned_agent_id, COUNT(*) AS aggregate')
            ->groupBy('assigned_agent_id')
            ->pluck('aggregate', 'assigned_agent_id')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();
    }

    public static function counts(TicketStatus $status): bool
    {
        return in_array($status, self::statuses(), true);
    }

    /**
     * Statuses in which an assigned ticket occupies one capacity slot of its agent.
     *
     * @return list<TicketStatus>
     */
    public static function statuses(): array
    {
        return [TicketStatus::Assigned, TicketStatus::InProgress, TicketStatus::Pending];
    }
}
