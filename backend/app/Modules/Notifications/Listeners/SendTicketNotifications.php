<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Models\User;
use App\Modules\Automation\Events\NoEligibleAgent;
use App\Modules\Notifications\Notifications\InternalNoteOnYourTicket;
use App\Modules\Notifications\Notifications\PublicReplyOnYourTicket;
use App\Modules\Notifications\Notifications\SlaBreachNotice;
use App\Modules\Notifications\Notifications\SlaWarningNotice;
use App\Modules\Notifications\Notifications\TicketAssignedToYou;
use App\Modules\Notifications\Notifications\TicketEscalated;
use App\Modules\Notifications\Notifications\TicketNotification;
use App\Modules\Notifications\Notifications\TicketUnassignable;
use App\Modules\Notifications\Support\Recipients;
use App\Modules\Sla\Events\SlaBreached;
use App\Modules\Sla\Events\SlaWarning;
use App\Modules\Tickets\Events\CommentAdded;
use App\Modules\Tickets\Events\PriorityChanged;
use App\Modules\Tickets\Events\TicketAssigned;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketAssignment;
use App\Modules\Tickets\Models\TicketComment;
use App\Support\Time\Clock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * The matrix of docs/04-domain/notifications.md: which after-commit domain event notifies whom.
 * All events carry ids only and fire inside the workspace, so every query here is tenant-scoped.
 * A user is never notified about what they did themselves.
 */
final readonly class SendTicketNotifications
{
    public function __construct(private Recipients $recipients, private Clock $clock) {}

    public function assigned(TicketAssigned $event): void
    {
        $ticket = Ticket::query()->find($event->ticketId);
        $assignment = TicketAssignment::query()->where('ticket_id', $event->ticketId)
            ->orderByDesc('created_at')->orderByDesc('id')->first();
        if ($ticket === null || $assignment === null) {
            return;
        }

        $this->send(
            $this->recipients->assignee($ticket),
            TicketAssignedToYou::class,
            $ticket,
            $assignment->id,
            $assignment->getAttribute('assigned_by_user_id'),
        );
    }

    public function commented(CommentAdded $event): void
    {
        $ticket = Ticket::query()->find($event->ticketId);
        $comment = TicketComment::query()->find($event->commentId);
        if ($ticket === null || $comment === null) {
            return;
        }

        $this->send(
            $this->recipients->assignee($ticket),
            $event->visibility === 'internal' ? InternalNoteOnYourTicket::class : PublicReplyOnYourTicket::class,
            $ticket,
            $comment->id,
            $comment->author_type === 'user' ? $comment->author_id : null,
        );
    }

    public function slaWarning(SlaWarning $event): void
    {
        $ticket = Ticket::query()->find($event->ticketId);
        if ($ticket !== null) {
            $this->send($this->recipients->assigneeOrTeam($ticket), SlaWarningNotice::class, $ticket, $event->timerId);
        }
    }

    public function slaBreached(SlaBreached $event): void
    {
        $ticket = Ticket::query()->find($event->ticketId);
        if ($ticket !== null) {
            $this->send(
                $this->recipients->merge($this->recipients->assignee($ticket), $this->recipients->managers()),
                SlaBreachNotice::class,
                $ticket,
                $event->timerId,
            );
        }
    }

    /** Only a raise is an escalation: P3 to P1 notifies, P1 to P3 does not. */
    public function priorityChanged(PriorityChanged $event): void
    {
        $ticket = Ticket::query()->find($event->ticketId);
        if ($ticket === null || strcmp($event->to, $event->from) >= 0) {
            return;
        }

        $this->send(
            $this->recipients->merge($this->recipients->assignee($ticket), $this->recipients->managers()),
            TicketEscalated::class,
            $ticket,
            // The same raise reported twice is one occurrence; a later raise to the same level is another.
            $event->to.':'.$this->clock->now()->format('YmdHi'),
        );
    }

    public function unassignable(NoEligibleAgent $event): void
    {
        $ticket = Ticket::query()->find($event->ticketId);
        if ($ticket !== null) {
            // One per ticket and day: every failed automatic attempt would otherwise notify again.
            $this->send($this->recipients->managers(), TicketUnassignable::class, $ticket, $ticket->id.':'.$this->clock->now()->format('Ymd'));
        }
    }

    /**
     * @param  Collection<int, User>  $users
     * @param  class-string<TicketNotification>  $notification
     */
    private function send(Collection $users, string $notification, Ticket $ticket, string $occurrence, ?string $exceptUserId = null): void
    {
        $users = $users->reject(fn (User $user): bool => $user->id === $exceptUserId);
        if ($users->isEmpty()) {
            return;
        }

        Notification::send($users, new $notification(
            (string) tenant('name'),
            (string) tenant('slug'),
            $ticket->id,
            $ticket->number,
            $ticket->title,
            $occurrence,
        ));
    }
}
