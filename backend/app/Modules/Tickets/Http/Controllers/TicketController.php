<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Http\Controllers;

use App\Models\User;
use App\Modules\Tickets\Actions\CreateTicket;
use App\Modules\Tickets\Actions\TransitionTicket;
use App\Modules\Tickets\Actions\UpdateTicket;
use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Http\Requests\IndexTicketsRequest;
use App\Modules\Tickets\Http\Requests\StoreTicketRequest;
use App\Modules\Tickets\Http\Requests\TransitionTicketRequest;
use App\Modules\Tickets\Http\Requests\UpdateTicketRequest;
use App\Modules\Tickets\Http\Resources\TicketEventResource;
use App\Modules\Tickets\Http\Resources\TicketResource;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Queries\TicketListQuery;
use App\Support\Auth\ApiClientPrincipal;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Tickets')]
final class TicketController
{
    /**
     * List tickets.
     *
     * Filters combine with AND; comma-separated values within one filter combine with OR.
     * `search` matches words (full text, stemmed), the ticket number, and short title fragments.
     */
    #[QueryParameter('filter[status]', 'Statuses, or `active` for everything not resolved or closed.', type: 'string')]
    #[QueryParameter('filter[priority]', 'Effective levels: P1–P4.', type: 'string')]
    #[QueryParameter('filter[assignee_id]', 'Agent ids, `unassigned`, or `me` (the signed-in user\'s Agent profile).', type: 'string')]
    #[QueryParameter('filter[team_id]', 'Team ids, or `none`.', type: 'string')]
    #[QueryParameter('filter[category_id]', 'Category ids.', type: 'string')]
    #[QueryParameter('filter[organization_id]', 'Organisation ids.', type: 'string')]
    #[QueryParameter('filter[contact_id]', 'Contact ids.', type: 'string')]
    #[QueryParameter('filter[tag]', 'Tag slugs.', type: 'string')]
    #[QueryParameter('filter[created_between]', 'YYYY-MM-DD,YYYY-MM-DD in the workspace time zone, inclusive.', type: 'string')]
    #[QueryParameter('filter[updated_since]', 'ISO-8601 instant, for pollers.', type: 'string')]
    #[QueryParameter('filter[sla_state]', 'State of the latest resolution timer: running, warning, breached, paused, met.', type: 'string')]
    #[QueryParameter('filter[has_duplicate_suggestion]', '`true`: tickets with a pending duplicate suggestion.', type: 'string')]
    #[QueryParameter('filter[impact]', 'Impact levels 1–4.', type: 'string')]
    #[QueryParameter('filter[urgency]', 'Urgency levels 1–4.', type: 'string')]
    #[QueryParameter('filter[number]', 'Ticket number.', type: 'integer')]
    #[QueryParameter('sort', 'priority_score, priority_level, created_at, updated_at, number, status or sla_due_at, optionally prefixed by -.', type: 'string')]
    #[QueryParameter('include', 'Any of contact, organization, category, tags.', type: 'string')]
    public function index(IndexTicketsRequest $request): AnonymousResourceCollection
    {
        return TicketResource::collection((new TicketListQuery($request->criteria()))->paginate($request->perPage()));
    }

    /**
     * Create a ticket.
     *
     * The ticket gets the next number of the workspace and starts `open`. API clients may send an
     * `Idempotency-Key` header: a repeat within 24 hours returns the first response.
     */
    #[HeaderParameter('Idempotency-Key', 'API clients: a unique key per ticket; a repeat within 24 hours returns the first response.', type: 'string', required: false)]
    #[Response(status: 201, type: TicketResource::class)]
    public function store(StoreTicketRequest $request, CreateTicket $create): JsonResponse
    {
        $principal = $request->user();

        /** @var array{title: string, description: string, contact_id: string, category_id: string, impact: int, urgency: int, tags?: list<string>, attachment_ids?: list<string>} $data */
        $data = $request->validated();
        $ticket = $principal instanceof ApiClientPrincipal
            ? $create($data, null, 'api', $principal->clientId())
            : $create($data, $principal instanceof User ? $principal : null, 'ui');

        return (new TicketResource($ticket->load(['contact', 'organization', 'category', 'tags'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Show a ticket with its contact, organisation, category and tags.
     */
    public function show(Ticket $ticket): TicketResource
    {
        return new TicketResource($ticket->load(['contact', 'organization', 'category', 'tags']));
    }

    /**
     * Edit the ticket fields that do not have their own guarded action.
     */
    public function update(UpdateTicketRequest $request, Ticket $ticket, UpdateTicket $update): TicketResource
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{title?: string, description?: string, category_id?: string, impact?: int, urgency?: int, tags?: list<string>} $data */
        $data = $request->validated();

        return new TicketResource($update($ticket, $data, $user)->load(['contact', 'organization', 'category', 'tags']));
    }

    /**
     * Move a ticket across a lifecycle edge. Assignment and duplicate edges have dedicated actions.
     */
    public function transition(TransitionTicketRequest $request, Ticket $ticket, TransitionTicket $transition): TicketResource
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{status: string, comment?: string|null} $data */
        $data = $request->validated();

        return new TicketResource($transition(
            $ticket,
            TicketStatus::from($data['status']),
            $data['comment'] ?? null,
            $user,
        )->load(['contact', 'organization', 'category', 'tags']));
    }

    /**
     * The ticket's history, newest first (cursor pagination).
     */
    #[QueryParameter('cursor', 'Opaque cursor from `meta.next_cursor` of the previous page.', type: 'string')]
    public function history(Request $request, Ticket $ticket): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'cursor' => ['sometimes', 'string', 'max:500'],
        ]);
        $perPage = (int) ($validated['per_page'] ?? 25);

        $events = $ticket->events()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($perPage);

        return TicketEventResource::collection($events);
    }
}
