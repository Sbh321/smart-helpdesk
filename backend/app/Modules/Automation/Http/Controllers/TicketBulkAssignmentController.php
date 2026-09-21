<?php

declare(strict_types=1);

namespace App\Modules\Automation\Http\Controllers;

use App\Modules\Automation\Actions\AssignTicket;
use App\Modules\Automation\Domain\Exceptions\NoEligibleAgentFound;
use App\Modules\Automation\Http\Requests\BulkAssignRequest;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketAssignment;
use App\Support\Http\Bulk\BulkRowResource;
use App\Support\Http\Bulk\BulkRun;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Tickets')]
final class TicketBulkAssignmentController
{
    /**
     * Assign up to 100 tickets.
     *
     * Assigns up to 100 tickets, each in its own transaction with the rules of the single endpoints:
     * one Agent or Team for all, or `auto: true` to run the assigner per ticket (a ticket nobody is
     * eligible for is a row with `no_eligible_agent`).
     */
    public function assign(BulkAssignRequest $request, AssignTicket $assign): AnonymousResourceCollection
    {
        $actorId = (string) $request->user()?->id;
        $auto = (bool) $request->validated('auto', false);
        $agentId = $request->validated('agent_id');
        $teamId = $request->validated('team_id');

        /** @var list<string> $ids */
        $ids = array_values($request->validated('ticket_ids'));
        $run = BulkRun::over($ids, function (string $id) use ($assign, $auto, $agentId, $teamId, $actorId): array {
            $ticket = Ticket::query()->findOrFail($id);
            $assigned = $auto
                ? $assign($ticket, actorId: $actorId)
                : $assign($ticket, agentId: is_string($agentId) ? $agentId : null, teamId: is_string($teamId) ? $teamId : null, actorId: $actorId);

            if ($auto && $assigned->assigned_agent_id === null) {
                /** @var TicketAssignment $assignment */
                $assignment = $assigned->getRelation('latestAssignment');
                /** @var list<array<string, mixed>> $exclusions */
                $exclusions = $assignment->explanation['excluded'] ?? [];

                throw NoEligibleAgentFound::for($assigned->id, $assigned->team_id, $exclusions);
            }

            return [
                'number' => $assigned->number,
                'status' => $assigned->status->value,
                'agent_id' => $assigned->assigned_agent_id,
                'team_id' => $assigned->team_id,
            ];
        }, Ticket::query()->whereKey($ids)->pluck('number', 'id')->all());

        return BulkRowResource::collection($run->rows())->additional(['meta' => [
            'total' => count($run->rows()),
            'succeeded' => $run->succeeded(),
            'failed' => count($run->rows()) - $run->succeeded(),
        ]]);
    }
}
