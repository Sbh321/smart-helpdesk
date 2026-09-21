<?php

declare(strict_types=1);

namespace App\Modules\Agents\Contracts;

use App\Modules\Agents\Models\AgentProfile;
use App\Modules\Agents\Models\Skill;
use App\Modules\Agents\Models\Team;

/**
 * What the Agent directory needs to know about the ticket world. Agents sits below Tickets in the
 * dependency order (docs/03-architecture/backend.md §Dependency rules), so it only states the
 * questions; Automation answers them (`Automation\Support\TicketDirectoryUsage`).
 */
interface DirectoryUsage
{
    /** Live count of unfinished tickets (not resolved, not closed) assigned to the agent; the deletion guard. */
    public function activeTicketCount(AgentProfile $agent): int;

    /**
     * Live workload (tickets in status assigned, in progress or pending) by effective priority.
     *
     * @return array{active_ticket_count: int, capacity: int, load: float, by_priority: array{P1: int, P2: int, P3: int, P4: int}}
     */
    public function workload(AgentProfile $agent): array;

    /**
     * What still points at the team.
     *
     * @return array{tickets: int, categories: int}
     */
    public function teamReferences(Team $team): array;

    /**
     * What still points at the skill.
     *
     * @return array{categories: int}
     */
    public function skillReferences(Skill $skill): array;
}
