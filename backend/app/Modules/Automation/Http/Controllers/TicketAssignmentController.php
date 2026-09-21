<?php

declare(strict_types=1);

namespace App\Modules\Automation\Http\Controllers;

use App\Modules\Automation\Actions\AssignTicket;
use App\Modules\Automation\Actions\UnassignTicket;
use App\Modules\Automation\Contracts\AssignmentStrategy;
use App\Modules\Automation\Domain\Exceptions\NoEligibleAgentFound;
use App\Modules\Automation\Http\Requests\AssignTicketRequest;
use App\Modules\Automation\Http\Resources\AssignedTicketResource;
use App\Modules\Automation\Http\Resources\AssignmentPreviewResource;
use App\Modules\Automation\Queries\AssignmentCandidates;
use App\Modules\Tickets\Http\Resources\TicketResource;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketAssignment;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;

#[Group('Automation')]
final class TicketAssignmentController
{
    private const TICKET_RELATIONS = ['contact', 'organization', 'category', 'tags'];

    /** Preview the assignment of a ticket. */
    public function candidates(Ticket $ticket, AssignmentCandidates $candidates, AssignmentStrategy $strategy): AssignmentPreviewResource
    {
        $pool = $candidates->forTicket($ticket);

        return new AssignmentPreviewResource($strategy->choose($pool->needs, $pool->candidates));
    }

    /**
     * Assign a ticket.
     *
     * Manual assignment, reassignment or team routing; the manager may pick an agent the
     * strategy would exclude (`assignment.explanation.manual_override`).
     */
    public function assign(AssignTicketRequest $request, Ticket $ticket, AssignTicket $assign): AssignedTicketResource
    {
        $assigned = $assign(
            $ticket,
            agentId: $request->validated('agent_id'),
            teamId: $request->validated('team_id'),
            actorId: (string) $request->user()?->id,
        );

        return new AssignedTicketResource($assigned->load(self::TICKET_RELATIONS));
    }

    /**
     * Run automatic assignment for a ticket.
     *
     * Runs the assigner for an unassigned ticket. When nobody is eligible the attempt is stored
     * and the answer is 422 `no_eligible_agent` with `meta.exclusions`.
     */
    public function autoAssign(Request $request, Ticket $ticket, AssignTicket $assign): AssignedTicketResource
    {
        $assigned = $assign($ticket, actorId: (string) $request->user()?->id);

        /** @var TicketAssignment $assignment */
        $assignment = $assigned->getRelation('latestAssignment');
        if ($assigned->assigned_agent_id === null) {
            /** @var list<array<string, mixed>> $exclusions */
            $exclusions = $assignment->explanation['excluded'] ?? [];

            throw NoEligibleAgentFound::for($assigned->id, $assigned->team_id, $exclusions);
        }

        return new AssignedTicketResource($assigned->load(self::TICKET_RELATIONS));
    }

    /** Unassign a ticket. */
    public function unassign(Request $request, Ticket $ticket, UnassignTicket $unassign): TicketResource
    {
        return new TicketResource($unassign($ticket, (string) $request->user()?->id)->load(self::TICKET_RELATIONS));
    }
}
