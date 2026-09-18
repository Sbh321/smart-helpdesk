<?php

declare(strict_types=1);

namespace App\Modules\Automation\Domain\Assignment;

use DateTimeInterface;

/**
 * Output of an assignment strategy: the chosen agent (or none), the ranked eligible agents and the exclusions.
 */
final readonly class AssignmentResult
{
    /**
     * @param  list<AgentCandidate>  $ranking  eligible agents, best first
     * @param  list<Exclusion>  $exclusions  sorted by agent id
     */
    public function __construct(
        public ?string $agentId,
        public array $ranking,
        public array $exclusions,
        public string $strategy,
        public string $strategyVersion,
    ) {}

    public function assigned(): bool
    {
        return $this->agentId !== null;
    }

    /**
     * @return array{strategy: string, strategy_version: string, ticket_id: string, agent_id: string|null, outcome: string, ranking: list<array{rank: int, agent_id: string, open_tickets: int, capacity: int, load: float, last_assigned_at: string|null}>, excluded: list<array{agent_id: string, reason: string, missing_skills?: list<string>}>}
     */
    public function explanation(string $ticketId): array
    {
        $ranking = [];

        foreach ($this->ranking as $index => $agent) {
            $ranking[] = [
                'rank' => $index + 1,
                'agent_id' => $agent->id,
                'open_tickets' => $agent->openTickets,
                'capacity' => $agent->capacity,
                'load' => round($agent->load(), 4),
                'last_assigned_at' => $agent->lastAssignedAt?->format(DateTimeInterface::ATOM),
            ];
        }

        return [
            'strategy' => $this->strategy,
            'strategy_version' => $this->strategyVersion,
            'ticket_id' => $ticketId,
            'agent_id' => $this->agentId,
            'outcome' => $this->assigned() ? 'assigned' : 'no_eligible_agent',
            'ranking' => $ranking,
            'excluded' => array_map(static fn (Exclusion $exclusion): array => $exclusion->toArray(), $this->exclusions),
        ];
    }
}
