<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Actions;

use App\Models\User;
use App\Modules\Tickets\Domain\Exceptions\InvalidDuplicateTarget;
use App\Modules\Tickets\Domain\Exceptions\InvalidTransition;
use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Events\TicketLifecycleChanged;
use App\Modules\Tickets\Events\TicketStatusChanged;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketDuplicateSuggestion;
use App\Modules\Tickets\Models\TicketEvent;
use App\Support\Time\Clock;
use Illuminate\Support\Facades\DB;

final readonly class MarkDuplicate
{
    public function __construct(private Clock $clock, private AddComment $addComment) {}

    public function __invoke(Ticket $ticket, Ticket $candidate, User $actor): Ticket
    {
        if ($ticket->id === $candidate->id) {
            throw new InvalidDuplicateTarget;
        }

        return DB::transaction(function () use ($ticket, $candidate, $actor): Ticket {
            $rows = Ticket::query()->whereIn('id', [$ticket->id, $candidate->id])
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $locked = $rows->get($ticket->id);
            $original = $rows->get($candidate->id);
            abort_if($locked === null || $original === null, 404);
            if ($locked->status !== TicketStatus::Open) {
                throw InvalidTransition::between($locked->status, TicketStatus::Closed, []);
            }
            if ($original->duplicate_of_id !== null) {
                throw new InvalidDuplicateTarget;
            }
            // Depth 1 in both directions (invariant 5): a ticket that is already the original of
            // other duplicates cannot itself become a duplicate, or those would sit at depth 2.
            if (Ticket::query()->where('duplicate_of_id', $locked->id)->exists()) {
                throw new InvalidDuplicateTarget;
            }

            $now = $this->clock->now();
            $locked->status = $locked->status->transitionTo(TicketStatus::Closed);
            $locked->duplicate_of_id = $original->id;
            $locked->resolved_at = $now;
            $locked->closed_at = $now;
            $locked->version = (int) $locked->version + 1;
            $locked->updated_at = $now;
            $locked->save();

            $suggestion = TicketDuplicateSuggestion::query()->firstOrCreate(
                ['ticket_id' => $locked->id, 'candidate_ticket_id' => $original->id],
                ['score' => 0, 'breakdown' => ['strategy' => 'manual'], 'decision' => 'pending',
                    'decided_by_user_id' => null, 'decided_at' => null],
            );
            $suggestion->forceFill(['decision' => 'accepted', 'decided_by_user_id' => $actor->id, 'decided_at' => $now])->save();

            TicketEvent::query()->create([
                'ticket_id' => $locked->id,
                'type' => 'duplicate_marked',
                'actor_type' => 'user',
                'actor_id' => $actor->id,
                'old_values' => ['status' => TicketStatus::Open->value, 'duplicate_of_id' => null],
                'new_values' => ['status' => TicketStatus::Closed->value, 'duplicate_of_id' => $original->id],
                'created_at' => $now,
            ]);
            ($this->addComment)($original, "Ticket #{$locked->number} marked as duplicate.", 'internal', 'user', $actor);
            event(new TicketLifecycleChanged($locked->tenant_id, $locked->id, TicketStatus::Open->value, TicketStatus::Closed->value));
            event(new TicketStatusChanged($locked->tenant_id, $locked->id, $actor->id, TicketStatus::Open->value, TicketStatus::Closed->value));

            return $locked;
        });
    }
}
