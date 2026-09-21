<?php

declare(strict_types=1);

namespace App\Modules\Automation\Http\Controllers;

use App\Models\User;
use App\Modules\Automation\Actions\SuggestDuplicates;
use App\Modules\Automation\Domain\Duplicates\TicketText;
use App\Modules\Automation\Exceptions\SuggestionAlreadyDecided;
use App\Modules\Tickets\Actions\MarkDuplicate;
use App\Modules\Tickets\Domain\DuplicatePreview;
use App\Modules\Tickets\Domain\DuplicatePreviewMatch;
use App\Modules\Tickets\Http\Requests\MarkDuplicateRequest;
use App\Modules\Tickets\Http\Requests\PreviewDuplicatesRequest;
use App\Modules\Tickets\Http\Resources\DuplicatePreviewResource;
use App\Modules\Tickets\Http\Resources\DuplicateSuggestionResource;
use App\Modules\Tickets\Http\Resources\TicketResource;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketDuplicateSuggestion;
use App\Support\Time\Clock;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Group('Tickets')]
final class TicketDuplicateController
{
    /** Find possible duplicates of a draft ticket. */
    public function preview(PreviewDuplicatesRequest $request, SuggestDuplicates $suggest, Clock $clock): DuplicatePreviewResource
    {
        $data = $request->validated();
        $result = $suggest(new TicketText((string) Str::uuid7(), $data['title'], $data['description'], $clock->now()));
        $candidates = Ticket::query()->whereIn('id', array_map(fn ($match) => $match->ticketId, $result->matches))
            ->get(['id', 'number', 'title'])->keyBy('id');
        $matches = [];
        foreach ($result->matches as $match) {
            $candidate = $candidates->get($match->ticketId);
            if ($candidate === null) {
                continue;
            }
            $matches[] = new DuplicatePreviewMatch(
                $candidate->id, $candidate->number, $candidate->title,
                round($match->score(), 4), $match->sharedWords,
            );
        }

        return new DuplicatePreviewResource(new DuplicatePreview(
            $result->strategy, $result->strategyVersion, $result->candidatesCompared, $matches,
        ));
    }

    /** List a ticket's duplicate suggestions. */
    public function index(Ticket $ticket): AnonymousResourceCollection
    {
        return DuplicateSuggestionResource::collection($ticket->duplicateSuggestions()
            ->with('candidate')->orderByDesc('score')->orderBy('id')->get());
    }

    /** Dismiss a duplicate suggestion. */
    public function dismiss(Request $request, Ticket $ticket, string $candidate, Clock $clock): DuplicateSuggestionResource
    {
        /** @var User $actor */
        $actor = $request->user();

        return DB::transaction(function () use ($ticket, $candidate, $actor, $clock): DuplicateSuggestionResource {
            $suggestion = TicketDuplicateSuggestion::query()->where('ticket_id', $ticket->id)
                ->where('candidate_ticket_id', $candidate)->lockForUpdate()->firstOrFail();
            if ($suggestion->decision === 'accepted') {
                throw SuggestionAlreadyDecided::accepted();
            }
            $suggestion->forceFill([
                'decision' => 'dismissed', 'decided_by_user_id' => $actor->id, 'decided_at' => $clock->now(),
            ])->save();

            return new DuplicateSuggestionResource($suggestion->load('candidate'));
        });
    }

    /** Mark a ticket as a duplicate. */
    public function mark(MarkDuplicateRequest $request, Ticket $ticket, MarkDuplicate $mark): TicketResource
    {
        /** @var User $actor */
        $actor = $request->user();
        $candidate = Ticket::query()->findOrFail($request->validated('candidate_ticket_id'));

        return new TicketResource($mark($ticket, $candidate, $actor)
            ->load(['contact', 'organization', 'category', 'tags']));
    }
}
