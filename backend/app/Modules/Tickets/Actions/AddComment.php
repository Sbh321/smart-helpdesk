<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Actions;

use App\Models\User;
use App\Modules\Media\Actions\AttachMedia;
use App\Modules\Media\Models\MediaItem;
use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Events\CommentAdded;
use App\Modules\Tickets\Events\FirstPublicReplyRecorded;
use App\Modules\Tickets\Events\TicketLifecycleChanged;
use App\Modules\Tickets\Events\TicketStatusChanged;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketComment;
use App\Modules\Tickets\Models\TicketEvent;
use App\Support\Time\Clock;
use Illuminate\Support\Facades\DB;

final readonly class AddComment
{
    public function __construct(
        private Clock $clock,
        private AttachMedia $attachMedia,
    ) {}

    /** @param list<string> $mediaIds */
    public function __invoke(Ticket $ticket, string $body, string $visibility, string $authorType, User $actor, array $mediaIds = []): TicketComment
    {
        return DB::transaction(function () use ($ticket, $body, $visibility, $authorType, $actor, $mediaIds): TicketComment {
            $locked = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $now = $this->clock->now();
            $comment = $locked->comments()->create([
                'visibility' => $visibility,
                'author_type' => $authorType,
                'author_id' => $authorType === 'contact' ? $locked->contact_id : $actor->id,
                'body' => $body,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            foreach ($mediaIds as $mediaId) {
                ($this->attachMedia)(MediaItem::query()->findOrFail($mediaId), 'ticket_comment', $comment->id);
            }
            $resumedFromPending = false;
            $firstPublicReply = false;

            if ($visibility === 'public' && $authorType === 'user') {
                if ($locked->first_responded_at === null) {
                    $locked->first_responded_at = $now;
                    $firstPublicReply = true;
                }
                $locked->last_agent_reply_at = $now;
            } elseif ($visibility === 'public' && $authorType === 'contact') {
                $locked->last_customer_reply_at = $now;
                if ($locked->status === TicketStatus::Pending) {
                    $locked->status = $locked->status->transitionTo(TicketStatus::InProgress);
                    $locked->pending_since = null;
                    $resumedFromPending = true;
                }
            }
            $locked->version++;
            $locked->updated_at = $now;
            $locked->save();

            if ($firstPublicReply) {
                // Synchronous hook: Sla meets the first-response timer inside this transaction.
                event(new FirstPublicReplyRecorded($locked->tenant_id, $locked->id));
            }

            if ($resumedFromPending) {
                // The requester replied, so the ticket leaves pending by itself; history shows it.
                TicketEvent::query()->create([
                    'ticket_id' => $locked->id,
                    'type' => 'status_changed',
                    'actor_type' => 'system',
                    'actor_id' => null,
                    'old_values' => ['status' => TicketStatus::Pending->value],
                    'new_values' => ['status' => TicketStatus::InProgress->value],
                    'note' => 'Requester replied',
                    'created_at' => $now,
                ]);
                event(new TicketLifecycleChanged($locked->tenant_id, $locked->id, TicketStatus::Pending->value, TicketStatus::InProgress->value));
                event(new TicketStatusChanged($locked->tenant_id, $locked->id, $actor->id, TicketStatus::Pending->value, TicketStatus::InProgress->value));
            }

            TicketEvent::query()->create([
                'ticket_id' => $locked->id,
                'type' => 'comment_added',
                'actor_type' => 'user',
                'actor_id' => $actor->id,
                'old_values' => [],
                'new_values' => ['comment_id' => $comment->id, 'visibility' => $visibility, 'author_type' => $authorType],
                'note' => null,
                'created_at' => $now,
            ]);
            event(new CommentAdded($locked->tenant_id, $locked->id, $comment->id, $visibility, $authorType));

            return $comment;
        });
    }
}
