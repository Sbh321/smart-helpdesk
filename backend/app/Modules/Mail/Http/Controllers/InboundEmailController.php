<?php

declare(strict_types=1);

namespace App\Modules\Mail\Http\Controllers;

use App\Modules\Mail\Http\Requests\IndexInboundEmailsRequest;
use App\Modules\Mail\Http\Resources\InboundEmailDetailResource;
use App\Modules\Mail\Http\Resources\InboundEmailResource;
use App\Modules\Mail\Models\InboundEmail;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The workspace's inbound email log (Settings → Email, docs/04-domain/email.md). Messages that named
 * no workspace are platform rows and never appear here: the query names the workspace and row-level
 * security hides them as well.
 */
#[Group('Settings')]
final class InboundEmailController
{
    /**
     * List inbound email.
     *
     * Newest first, cursor-paginated (default 25, at most 100 a page).
     */
    #[QueryParameter('cursor', 'Opaque cursor from `meta.next_cursor` of the previous page.', type: 'string')]
    #[QueryParameter('per_page', 'Page size, 1 to 100 (default 25).', type: 'integer')]
    #[QueryParameter('filter[state]', 'States, comma-separated: comment, ticket, ignored, unrouted, rejected.', type: 'string')]
    #[QueryParameter('filter[ticket_id]', 'Messages routed to these tickets, comma-separated ids.', type: 'string')]
    public function index(IndexInboundEmailsRequest $request): AnonymousResourceCollection
    {
        $query = InboundEmail::query()->where('tenant_id', (string) tenant()?->getTenantKey())->with('ticket:id,tenant_id,number,title');

        foreach (['state', 'ticket_id'] as $column) {
            $values = $request->filterValues($column);
            if ($values !== []) {
                $query->whereIn($column, $values);
            }
        }

        return InboundEmailResource::collection(
            $query->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate($request->perPage())->withQueryString(),
        );
    }

    /**
     * Show an inbound email.
     *
     * The log row with the parsed reply, the text part and the raw headers.
     */
    public function show(string $inboundEmail): InboundEmailDetailResource
    {
        $row = InboundEmail::query()
            ->where('tenant_id', (string) tenant()?->getTenantKey())
            ->with('ticket:id,tenant_id,number,title')
            ->findOrFail($inboundEmail);

        return new InboundEmailDetailResource($row);
    }
}
