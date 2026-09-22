<?php

declare(strict_types=1);

namespace App\Modules\Mail\Listeners;

use App\Modules\Mail\Notifications\PublicReplyToContact;
use App\Modules\Mail\Support\WorkspaceMailIdentity;
use App\Modules\Tickets\Events\CommentAdded;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketComment;
use Illuminate\Support\Facades\Notification;

/**
 * Mails an agent's public reply to the requester. Internal notes and replies recorded on behalf
 * of the requester are never mailed. `CommentAdded` is dispatched after commit.
 */
final class SendPublicReplyToContact
{
    public function __construct(private readonly WorkspaceMailIdentity $identity) {}

    public function handle(CommentAdded $event): void
    {
        if ($event->visibility !== 'public' || $event->authorType !== 'user') {
            return;
        }

        $ticket = Ticket::query()->with('contact')->findOrFail($event->ticketId);
        $comment = TicketComment::query()->findOrFail($event->commentId);

        if ($ticket->contact->isArchived()) {
            return;
        }

        // Position of this reply among the ticket's public agent replies: the mail thread sequence.
        $sequence = TicketComment::query()
            ->where('ticket_id', $ticket->id)
            ->where('visibility', 'public')
            ->where('author_type', 'user')
            ->where('created_at', '<=', $comment->created_at)
            ->count();

        // The sender is the workspace's identity (Settings → Email), resolved here where the tenant is known.
        $identity = $this->identity->current();

        Notification::route('mail', [$ticket->contact->email => $ticket->contact->name])->notify(new PublicReplyToContact(
            $identity->workspaceName,
            $identity->senderName(),
            $identity->senderAddress(),
            $ticket->id,
            $ticket->number,
            $ticket->title,
            $comment->body,
            $ticket->contact_id,
            $sequence,
        ));
    }
}
