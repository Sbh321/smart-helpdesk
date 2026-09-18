<?php

declare(strict_types=1);

namespace App\Modules\Automation\Strategies\Baseline;

use App\Modules\Automation\Contracts\AssignmentStrategy;
use App\Modules\Automation\Domain\Assignment\AgentCandidate;
use App\Modules\Automation\Domain\Assignment\AssignmentResult;
use App\Modules\Automation\Domain\Assignment\Exclusion;
use App\Modules\Automation\Domain\Assignment\ExclusionReason;
use App\Modules\Automation\Domain\Assignment\TicketNeeds;
use App\Support\Attributes\AcademicBaseline;

/**
 * Least-Loaded Eligible Agent: among eligible agents pick the lowest open ÷ capacity; ties go to the
 * oldest last assignment (never assigned first), then the smallest id (docs/05-algorithms/agent-assignment.md).
 *
 * @deprecated Academic baseline for the CACS452 defence; replace after the defence (docs/adr/0023-minimal-replaceable-algorithms.md).
 */
#[AcademicBaseline]
final readonly class LeastLoadedAgent implements AssignmentStrategy
{
    public const string NAME = 'least_loaded_agent';

    public const string VERSION = '1.0.0';

    public function choose(TicketNeeds $ticket, array $candidates): AssignmentResult
    {
        $eligible = [];
        $exclusions = [];

        foreach ($candidates as $agent) {
            $exclusion = $this->whyNotEligible($agent, $ticket);

            if ($exclusion === null) {
                $eligible[] = $agent;
            } else {
                $exclusions[] = $exclusion;
            }
        }

        usort($eligible, $this->compare(...));
        usort($exclusions, static fn (Exclusion $a, Exclusion $b): int => strcmp($a->agentId, $b->agentId));

        return new AssignmentResult(
            agentId: $eligible === [] ? null : $eligible[0]->id,
            ranking: $eligible,
            exclusions: $exclusions,
            strategy: self::NAME,
            strategyVersion: self::VERSION,
        );
    }

    private function whyNotEligible(AgentCandidate $agent, TicketNeeds $ticket): ?Exclusion
    {
        $reason = match (true) {
            ! $agent->active => ExclusionReason::Inactive,
            ! $agent->available => ExclusionReason::NotAvailable,
            $ticket->enforceShifts && ! $agent->onShift => ExclusionReason::OffShift,
            default => null,
        };

        if ($reason !== null) {
            return new Exclusion($agent->id, $reason);
        }

        $missing = array_values(array_unique(array_diff($ticket->requiredSkills, $agent->skills)));

        if ($missing !== []) {
            sort($missing);

            return new Exclusion($agent->id, ExclusionReason::MissingSkill, $missing);
        }

        if ($ticket->teamId !== null && ! in_array($ticket->teamId, $agent->teamIds, true)) {
            return new Exclusion($agent->id, ExclusionReason::NotInTeam);
        }

        if ($agent->openTickets >= $agent->capacity) {
            return new Exclusion($agent->id, ExclusionReason::AtCapacity);
        }

        return null;
    }

    private function compare(AgentCandidate $a, AgentCandidate $b): int
    {
        return $a->compareLoad($b)
            ?: $this->compareLastAssigned($a, $b)
            ?: strcmp($a->id, $b->id);
    }

    private function compareLastAssigned(AgentCandidate $a, AgentCandidate $b): int
    {
        return match (true) {
            $a->lastAssignedAt === null && $b->lastAssignedAt === null => 0,
            $a->lastAssignedAt === null => -1,
            $b->lastAssignedAt === null => 1,
            default => $a->lastAssignedAt <=> $b->lastAssignedAt,
        };
    }
}
