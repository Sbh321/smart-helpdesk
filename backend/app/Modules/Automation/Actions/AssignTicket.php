<?php

declare(strict_types=1);

namespace App\Modules\Automation\Actions;

use App\Modules\Agents\Models\Team;
use App\Modules\Automation\Contracts\AssignmentStrategy;
use App\Modules\Automation\Domain\Assignment\AssignmentResult;
use App\Modules\Automation\Domain\Assignment\Exclusion;
use App\Modules\Automation\Domain\Exceptions\TicketAlreadyAssigned;
use App\Modules\Automation\Events\NoEligibleAgent;
use App\Modules\Automation\Queries\AgentPool;
use App\Modules\Automation\Queries\AgentWorkload;
use App\Modules\Automation\Queries\AssignmentCandidates;
use App\Modules\Automation\Support\AgentWorkloadCounter;
use App\Modules\Tenancy\Settings\Settings;
use App\Modules\Tickets\Domain\Exceptions\InvalidTransition;
use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Events\TicketAssigned;
use App\Modules\Tickets\Events\TicketStatusChanged;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketAssignment;
use App\Modules\Tickets\Models\TicketEvent;
use App\Support\Time\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Assigns a ticket (docs/04-domain/agents-and-teams.md, docs/05-algorithms/agent-assignment.md).
 *
 * Three modes, chosen by the arguments:
 *  - automatic (`$agentId` and `$teamId` null): the `AssignmentStrategy` picks. When nobody is
 *    eligible the ticket stays open with its routed team, the attempt and every exclusion are
 *    stored, `NoEligibleAgent` fires after commit and the ticket is returned; it never throws for
 *    that. An already assigned ticket is refused (`already_assigned`): unassign or reassign it.
 *  - manual (`$agentId`): a manager's decision. Any agent of the workspace whose user is active
 *    may be picked, even offline or at capacity; the strategy still runs and its ranking is
 *    stored, with `manual_override: true` and the failed rule when the agent was not eligible.
 *    A different agent on an assigned ticket is a reassignment (`reason = reassign`,
 *    `previous_agent_profile_id`); the same agent again is `already_assigned`.
 *  - team routing (`$teamId` only): moves the ticket to a team queue and keeps the agent.
 *
 * Capacity race. Two concurrent assignments must not both take an agent's last slot. Inside the
 * transaction the ticket row is locked first, then every agent row of the workspace is read
 * `FOR UPDATE` in id order, and only then are the stored workload counters read and the strategy
 * run. A second transaction blocks on the first agent row until the first one commits; under
 * PostgreSQL's READ COMMITTED a blocked `FOR UPDATE` then returns the committed row, so it sees
 * the incremented counter and excludes the agent with `at_capacity`. Reading after the lock makes
 * a separate re-check unnecessary, and the fixed order (ticket, then agents by id) cannot
 * deadlock with the workload listener, which locks a ticket and then a single agent.
 * MVP-SHORTCUT: this serialises assignments per workspace, fine for tens of agents; V1: lock only the ranked candidates.
 *
 * The assignment row is attached to the returned ticket as the `latestAssignment` relation.
 */
final readonly class AssignTicket
{
    public function __construct(
        private AssignmentCandidates $candidates,
        private AssignmentStrategy $strategy,
        private AgentWorkloadCounter $counter,
        private Clock $clock,
        private Settings $settings,
    ) {}

    /**
     * @throws TicketAlreadyAssigned
     * @throws InvalidTransition for a resolved or closed ticket
     * @throws ValidationException for an agent or team outside the workspace, or an inactive agent
     */
    public function __invoke(Ticket $ticket, ?string $agentId = null, ?string $teamId = null, ?string $actorId = null): Ticket
    {
        return DB::transaction(function () use ($ticket, $agentId, $teamId, $actorId): Ticket {
            $locked = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;
            $previousAgentId = $locked->assigned_agent_id;
            $previousTeamId = $locked->team_id;
            $manual = $agentId !== null || $teamId !== null;

            if (! $from->isActive()) {
                throw InvalidTransition::between($from, TicketStatus::Assigned);
            }

            $sameAgent = $agentId === null || $agentId === $previousAgentId;
            $sameTeam = $teamId === null || $teamId === $previousTeamId;
            if ($manual ? ($sameAgent && $sameTeam) : $previousAgentId !== null) {
                throw TicketAlreadyAssigned::to($locked->id, $previousAgentId, $previousTeamId);
            }

            if ($teamId !== null) {
                if (! Team::query()->whereKey($teamId)->exists()) {
                    throw ValidationException::withMessages(['team_id' => 'The Team does not exist in this Workspace.']);
                }
                $locked->team_id = $teamId;
            }

            // Locks the agent rows; nothing about workload is read before this line.
            $pool = $this->candidates->forTicket($locked, lock: true);
            $result = $this->strategy->choose($pool->needs, $pool->candidates);
            $locked->team_id = $pool->needs->teamId;

            $chosenId = match (true) {
                $agentId !== null => $this->manualChoice($pool, $agentId),
                $teamId !== null => $previousAgentId,
                default => $result->agentId,
            };
            $agentChanged = $chosenId !== null && $chosenId !== $previousAgentId;

            $now = $this->clock->now();
            if ($agentChanged && $from === TicketStatus::Open) {
                $locked->status = $from->transitionTo(TicketStatus::Assigned);
            }
            $locked->assigned_agent_id = $chosenId;
            $locked->version++;
            $locked->updated_at = $now;
            $locked->save();

            if ($agentChanged) {
                if ($previousAgentId !== null && AgentWorkload::counts($from)) {
                    $this->counter->decrement($previousAgentId);
                }
                // Open became assigned above, so the new status always occupies a slot.
                $this->counter->increment($chosenId, $now);
            }

            $explanation = $this->explanation($result, $locked, $chosenId, $agentId, $teamId);
            $assignment = TicketAssignment::query()->create([
                'ticket_id' => $locked->id,
                'team_id' => $locked->team_id,
                'agent_profile_id' => $chosenId,
                'previous_agent_profile_id' => $agentChanged ? $previousAgentId : null,
                'reason' => match (true) {
                    ! $manual => 'auto',
                    $agentChanged && $previousAgentId !== null => 'reassign',
                    default => 'manual',
                },
                'assigned_by_user_id' => $actorId,
                'explanation' => $explanation,
                'settings_version' => $this->settings->version(),
                'created_at' => $now,
            ]);
            TicketEvent::query()->create([
                'ticket_id' => $locked->id,
                'type' => 'assigned',
                'actor_type' => $actorId === null ? 'system' : 'user',
                'actor_id' => $actorId,
                'old_values' => ['status' => $from->value, 'agent_id' => $previousAgentId, 'team_id' => $previousTeamId],
                'new_values' => [
                    'status' => $locked->status->value,
                    'agent_id' => $chosenId,
                    'team_id' => $locked->team_id,
                    'outcome' => $explanation['outcome'],
                ],
                'note' => null,
                'created_at' => $now,
            ]);

            // All three implement ShouldDispatchAfterCommit.
            if ($agentChanged) {
                event(new TicketAssigned($locked->tenant_id, $locked->id, $chosenId));
            }
            if ($from !== $locked->status) {
                event(new TicketStatusChanged($locked->tenant_id, $locked->id, $actorId, $from->value, $locked->status->value));
            }
            if ($explanation['outcome'] === 'no_eligible_agent') {
                event(new NoEligibleAgent($locked->tenant_id, $locked->id, $locked->team_id, $explanation['excluded']));
            }

            return $locked->setRelation('latestAssignment', $assignment);
        });
    }

    private function manualChoice(AgentPool $pool, string $agentId): string
    {
        $candidate = $pool->candidate($agentId);
        if ($candidate === null) {
            throw ValidationException::withMessages(['agent_id' => 'The Agent does not exist in this Workspace.']);
        }
        if (! $candidate->active) {
            throw ValidationException::withMessages(['agent_id' => 'The Agent\'s user account is disabled.']);
        }

        return $agentId;
    }

    /**
     * The strategy's explanation plus what was actually done with it.
     *
     * @return array{outcome: string, excluded: list<array<string, mixed>>, ...<string, mixed>}
     */
    private function explanation(AssignmentResult $result, Ticket $ticket, ?string $chosenId, ?string $agentId, ?string $teamId): array
    {
        $explanation = $result->explanation($ticket->id);
        $explanation['recommended_agent_id'] = $result->agentId;
        $explanation['agent_id'] = $chosenId;
        $explanation['team_id'] = $ticket->team_id;
        $explanation['selection'] = $agentId === null && $teamId === null ? 'automatic' : 'manual';
        $explanation['outcome'] = match (true) {
            $agentId === null && $teamId !== null => 'team_routed',
            $chosenId === null => 'no_eligible_agent',
            default => 'assigned',
        };

        if ($agentId !== null) {
            $failed = array_values(array_filter(
                $result->exclusions,
                static fn (Exclusion $exclusion): bool => $exclusion->agentId === $agentId,
            ));
            $explanation['manual_override'] = $failed !== [];
            if ($failed !== []) {
                $explanation['override_reason'] = $failed[0]->reason->value;
            }
        }

        return $explanation;
    }
}
