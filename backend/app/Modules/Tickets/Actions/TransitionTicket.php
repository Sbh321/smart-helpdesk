<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Actions;

use App\Models\User;
use App\Modules\Tickets\Domain\Exceptions\InvalidTransition;
use App\Modules\Tickets\Domain\Exceptions\ResolutionCommentRequired;
use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Events\TicketLifecycleChanged;
use App\Modules\Tickets\Events\TicketStatusChanged;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketEvent;
use App\Modules\Tickets\Queries\TicketTransitionRules;
use App\Support\Time\Clock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class TransitionTicket
{
    public function __construct(
        private Clock $clock,
        private TicketTransitionRules $rules,
        private AddComment $addComment,
    ) {}

    public function __invoke(Ticket $ticket, TicketStatus $target, ?string $comment, User $actor): Ticket
    {
        return DB::transaction(function () use ($ticket, $target, $comment, $actor): Ticket {
            $locked = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;
            $safeTargets = $this->rules->safeTargets($locked);

            if (! in_array($target, $safeTargets, true)) {
                $reason = match (true) {
                    ! $this->rules->isReopen($from, $target) => null,
                    $locked->duplicate_of_id !== null => 'closed_as_duplicate',
                    ! $this->rules->canReopen($locked) => 'reopen_window_expired',
                    default => null,
                };

                throw InvalidTransition::between($from, $target, $safeTargets, $reason);
            }

            if (! $this->rules->hasTargetPermission($from, $target, $actor)) {
                throw new AuthorizationException;
            }

            $now = $this->clock->now();
            $comment = is_string($comment) ? trim($comment) : null;

            if ($target === TicketStatus::Resolved) {
                $lastComment = $locked->comments()
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->first(['visibility', 'author_type']);
                $hasAgentResolution = $lastComment?->visibility === 'public'
                    && $lastComment->author_type === 'user';

                if (($comment === null || $comment === '') && ! $hasAgentResolution) {
                    throw ResolutionCommentRequired::make();
                }

                if ($comment !== null && $comment !== '') {
                    ($this->addComment)($locked, $comment, 'public', 'user', $actor);
                    $locked->refresh();
                }
            }

            $oldValues = ['status' => $from->value];
            $newValues = ['status' => $target->value];
            // The enum owns the transition table; the rules above only narrow it.
            $locked->status = $from->transitionTo($target);
            $locked->pending_since = $target === TicketStatus::Pending ? $now : null;

            if ($target === TicketStatus::Resolved) {
                $locked->resolved_at = $now;
                $locked->closed_at = null;
                $newValues['resolved_at'] = $now->toIso8601ZuluString();
            } elseif ($target === TicketStatus::Closed) {
                $locked->closed_at = $now;
                $newValues['closed_at'] = $now->toIso8601ZuluString();
            } elseif ($this->rules->isReopen($from, $target)) {
                $locked->resolved_at = null;
                $locked->closed_at = null;
                $locked->reopen_count = (int) $locked->reopen_count + 1;
                $newValues['resolved_at'] = null;
                $newValues['closed_at'] = null;
                $newValues['reopen_count'] = $locked->reopen_count;
            }

            $locked->version = (int) $locked->version + 1;
            $locked->updated_at = $now;
            $locked->save();

            TicketEvent::query()->create([
                'ticket_id' => $locked->id,
                'type' => $this->rules->isReopen($from, $target) ? 'reopened' : 'status_changed',
                'actor_type' => 'user',
                'actor_id' => $actor->id,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'note' => $comment === null || $comment === '' ? null : Str::limit($comment, 255, ''),
                'created_at' => $now,
            ]);

            event(new TicketLifecycleChanged($locked->tenant_id, $locked->id, $from->value, $target->value));

            // Post-commit consumers (including M2-07 comment notifications) remain on this event.
            event(new TicketStatusChanged($locked->tenant_id, $locked->id, $actor->id, $from->value, $target->value));

            return $locked;
        });
    }
}
