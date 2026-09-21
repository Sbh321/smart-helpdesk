<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Http\Controllers;

use App\Models\User;
use App\Modules\Media\Http\Resources\MediaItemResource;
use App\Modules\Tickets\Actions\AddComment;
use App\Modules\Tickets\Http\Requests\AddCommentRequest;
use App\Modules\Tickets\Http\Resources\TicketCommentResource;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketComment;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Tickets')]
final class TicketCommentController
{
    #[QueryParameter('page', 'One-based page number.', type: 'integer')]
    public function index(Request $request, Ticket $ticket): AnonymousResourceCollection
    {
        $query = $ticket->comments()->with('mediaLinks.item')->orderBy('created_at')->orderBy('id');
        if (! $request->user()?->can('comments.internal')) {
            $query->where('visibility', 'public');
        }

        $page = $query->paginate(50);
        // One preload for every attachment on the page, instead of three queries per attachment.
        MediaItemResource::preload(collect($page->items())->flatMap(
            fn (TicketComment $comment) => $comment->mediaLinks->pluck('item')->filter(),
        ));

        return TicketCommentResource::collection($page);
    }

    #[ScrambleResponse(status: 201, type: TicketCommentResource::class)]
    public function store(AddCommentRequest $request, Ticket $ticket, AddComment $add): JsonResponse
    {
        $data = $request->validated();
        if ($data['visibility'] === 'internal' && ! $request->user()?->can('comments.internal')) {
            throw new AuthorizationException;
        }
        /** @var User $actor */
        $actor = $request->user();

        return (new TicketCommentResource($add(
            $ticket,
            $data['body'],
            $data['visibility'],
            $data['author_type'] ?? 'user',
            $actor,
            $data['media_ids'] ?? [],
        )->load('mediaLinks.item')))->response()->setStatusCode(201);
    }
}
