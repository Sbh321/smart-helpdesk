<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Http\Controllers;

use App\Models\User;
use App\Modules\Tickets\Actions\TransitionTicket;
use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Http\Requests\BulkTransitionRequest;
use App\Modules\Tickets\Models\Ticket;
use App\Support\Http\Bulk\BulkRowResource;
use App\Support\Http\Bulk\BulkRun;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Tickets')]
final class TicketBulkController
{
    /**
     * Transition up to 100 tickets.
     *
     * Moves up to 100 tickets to one status. Each ticket is its own transaction with the same rules
     * as `POST /tickets/{id}/transition`; the answer lists the outcome per ticket, so a partial failure
     * is a 200 with some rows `ok: false`.
     */
    public function transition(BulkTransitionRequest $request, TransitionTicket $transition): AnonymousResourceCollection
    {
        /** @var User $actor */
        $actor = $request->user();
        $target = TicketStatus::from((string) $request->validated('status'));
        $comment = $request->validated('comment');

        /** @var list<string> $ids */
        $ids = array_values($request->validated('ticket_ids'));
        $run = BulkRun::over($ids, function (string $id) use ($transition, $target, $comment, $actor): array {
            $ticket = $transition(Ticket::query()->findOrFail($id), $target, is_string($comment) ? $comment : null, $actor);

            return ['number' => $ticket->number, 'status' => $ticket->status->value];
        }, Ticket::query()->whereKey($ids)->pluck('number', 'id')->all());

        return BulkRowResource::collection($run->rows())->additional(['meta' => [
            'total' => count($run->rows()),
            'succeeded' => $run->succeeded(),
            'failed' => count($run->rows()) - $run->succeeded(),
        ]]);
    }
}
