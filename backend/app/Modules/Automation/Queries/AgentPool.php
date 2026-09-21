<?php

declare(strict_types=1);

namespace App\Modules\Automation\Queries;

use App\Modules\Automation\Domain\Assignment\AgentCandidate;
use App\Modules\Automation\Domain\Assignment\TicketNeeds;

/**
 * The strategy input for one ticket: what it needs and every agent of its tenant.
 */
final readonly class AgentPool
{
    /** @param list<AgentCandidate> $candidates ordered by agent id */
    public function __construct(
        public TicketNeeds $needs,
        public array $candidates,
    ) {}

    public function candidate(string $agentId): ?AgentCandidate
    {
        foreach ($this->candidates as $candidate) {
            if ($candidate->id === $agentId) {
                return $candidate;
            }
        }

        return null;
    }
}
