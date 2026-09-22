<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\Mail\Events\InboundEmailProcessed;
use App\Modules\Notifications\Notifications\InboundEmailRejected;
use App\Modules\Notifications\Support\Recipients;
use App\Modules\Tickets\Models\Ticket;

/**
 * "Inbound email rejected" of docs/04-domain/notifications.md: a message that reached a workspace and
 * was refused tells that workspace's mail admins (`mail.manage`) and the assignee of the ticket it was
 * aimed at. Unrouted and ignored messages notify nobody: an unrouted one names no workspace, and
 * auto-replies are noise. Runs inside the workspace the message was logged in.
 */
final readonly class SendInboundEmailNotifications
{
    public function __construct(private Recipients $recipients) {}

    public function processed(InboundEmailProcessed $event): void
    {
        if ($event->tenantId === null || $event->state !== 'rejected') {
            return;
        }

        $ticket = $event->ticketId === null ? null : Ticket::query()->find($event->ticketId);
        $users = $this->recipients->merge(
            $this->recipients->withPermission('mail.manage'),
            $ticket === null ? $this->recipients->merge() : $this->recipients->assignee($ticket),
        );

        foreach ($users as $user) {
            $user->notify(new InboundEmailRejected($event->inboundEmailId, (string) $event->reason, $ticket?->id, $ticket?->number, $ticket?->title));
        }
    }
}
