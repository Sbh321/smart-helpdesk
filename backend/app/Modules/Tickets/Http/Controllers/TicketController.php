<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Http\Controllers;

use App\Models\User;
use App\Modules\Tickets\Actions\CreateTicket;
use App\Modules\Tickets\Http\Requests\IndexTicketsRequest;
use App\Modules\Tickets\Http\Requests\StoreTicketRequest;
use App\Modules\Tickets\Http\Resources\TicketEventResource;
use App\Modules\Tickets\Http\Resources\TicketResource;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Queries\TicketListQuery;
use Dedoc\Scramble\Attributes\Group;
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
    #[QueryParameter('filter[assignee_id]', 'Agent ids, or `unassigned`.', type: 'string')]
    #[QueryParameter('filter[team_id]', 'Team ids, or `none`.', type: 'string')]
    #[QueryParameter('filter[category_id]', 'Category ids.', type: 'string')]
    #[QueryParameter('filter[organization_id]', 'Organisation ids.', type: 'string')]
    #[QueryParameter('filter[contact_id]', 'Contact ids.', type: 'string')]
    #[QueryParameter('filter[tag]', 'Tag slugs.', type: 'string')]
    #[QueryParameter('filter[created_between]', 'YYYY-MM-DD,YYYY-MM-DD in the workspace time zone, inclusive.', type: 'string')]
    #[QueryParameter('filter[updated_since]', 'ISO-8601 instant, for pollers.', type: 'string')]
    #[QueryParameter('include', 'Any of contact, organization, category, tags.', type: 'string')]
    public function index(IndexTicketsRequest $request): AnonymousResourceCollection
    {
        return TicketResource::collection((new TicketListQuery($request))->paginate());
    }

    /**
     * Create a ticket.
     *
     * The ticket gets the next number of the workspace and starts `open`.
     */
    #[Response(status: 201, type: TicketResource::class)]
    public function store(StoreTicketRequest $request, CreateTicket $create): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        /** @var array{title: string, description: string, contact_id: string, category_id: string, impact: int, urgency: int, tags?: list<string>} $data */
        $data = $request->validated();
        $ticket = $create($data, $user, 'ui');

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
