<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Notifications;

use App\Models\User;
use App\Modules\Notifications\Channels\TenantDatabaseChannel;
use App\Modules\Notifications\Contracts\StorableNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * "Inbound email rejected" (docs/04-domain/notifications.md, docs/04-domain/email.md): in-app only,
 * to the workspace's mail admins and, for a message aimed at a ticket, its assignee. Never mailed:
 * answering inbound mail with mail is how loops start.
 */
final class InboundEmailRejected extends Notification implements ShouldQueue, StorableNotification
{
    use Queueable;

    public function __construct(
        public readonly string $inboundEmailId,
        public readonly string $reason,
        public readonly ?string $ticketId,
        public readonly ?int $ticketNumber,
        public readonly ?string $ticketTitle,
    ) {
        $this->onQueue('notifications');
    }

    public function kind(): string
    {
        return 'inbound_email_rejected';
    }

    public function key(): string
    {
        return 'inbound_email_rejected:'.$this->inboundEmailId;
    }

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return [TenantDatabaseChannel::class];
    }

    /**
     * @return array{kind: string, inbound_email_id: string, ticket_id: string|null, ticket_number: int|null, ticket_title: string|null, summary: string}
     */
    public function toArray(User $notifiable): array
    {
        $why = match ($this->reason) {
            'sender_not_allowed' => 'the sender is not a contact of the ticket',
            'tenant_mismatch' => 'it named another workspace',
            'unknown_sender' => 'the sender is not a contact',
            'sender_archived' => 'the sender is an archived contact',
            'too_large' => 'it is too large',
            'no_category' => 'the workspace has no active category',
            default => 'it could not be routed',
        };

        return [
            'kind' => $this->kind(),
            'inbound_email_id' => $this->inboundEmailId,
            'ticket_id' => $this->ticketId,
            'ticket_number' => $this->ticketNumber,
            'ticket_title' => $this->ticketTitle,
            'summary' => $this->ticketNumber === null
                ? "An inbound email was rejected: {$why}"
                : "An email to ticket #{$this->ticketNumber} was rejected: {$why}",
        ];
    }
}
