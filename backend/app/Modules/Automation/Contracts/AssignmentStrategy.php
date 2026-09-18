<?php

declare(strict_types=1);

namespace App\Modules\Automation\Contracts;

use App\Modules\Automation\Domain\Assignment\AgentCandidate;
use App\Modules\Automation\Domain\Assignment\AssignmentResult;
use App\Modules\Automation\Domain\Assignment\TicketNeeds;

/**
 * Chooses the agent for a ticket (docs/05-algorithms/agent-assignment.md, ADR-0023).
 *
 * Implementations must be deterministic and independent of the order of the candidates.
 */
interface AssignmentStrategy
{
    /**
     * @param  list<AgentCandidate>  $candidates  all agents of the ticket's tenant
     */
    public function choose(TicketNeeds $ticket, array $candidates): AssignmentResult;
}
