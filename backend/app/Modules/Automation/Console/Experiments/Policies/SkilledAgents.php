<?php

declare(strict_types=1);

namespace App\Modules\Automation\Console\Experiments\Policies;

use App\Modules\Automation\Domain\Assignment\AgentCandidate;
use App\Modules\Automation\Domain\Assignment\TicketNeeds;

/**
 * The agents a comparison policy may pick: active, available and holding every required skill.
 * Unlike the baseline, capacity is not checked, so these policies can overload an agent.
 */
final class SkilledAgents
{
    /**
     * @param  list<AgentCandidate>  $candidates
     * @return list<AgentCandidate> sorted by id, so the input order never matters
     */
    public static function of(TicketNeeds $ticket, array $candidates): array
    {
        $skilled = array_values(array_filter(
            $candidates,
            fn (AgentCandidate $agent): bool => $agent->active && $agent->available
                && array_diff($ticket->requiredSkills, $agent->skills) === [],
        ));
        usort($skilled, fn (AgentCandidate $a, AgentCandidate $b): int => strcmp($a->id, $b->id));

        return $skilled;
    }
}
