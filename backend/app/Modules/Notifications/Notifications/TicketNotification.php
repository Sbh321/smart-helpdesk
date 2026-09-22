<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Notifications;

use App\Models\User;
use App\Modules\Mail\Support\TicketThread;
use App\Modules\Notifications\Channels\TenantDatabaseChannel;
use App\Modules\Notifications\Contracts\StorableNotification;
use App\Support\Mail\PlatformSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Symfony\Component\Mime\Email;

/**
 * Base of the notifications about a ticket (docs/04-domain/notifications.md). A subclass names its
 * kind, its sentence and whether it is also mailed; the channel list per kind is static in the MVP.
 * The payload carries ids and a summary only; the SPA refetches what it shows.
 */
abstract class TicketNotification extends Notification implements ShouldQueue, StorableNotification
{
    use Queueable;

    /** Stored as `notifications.type` and sent as `kind`: `ticket_assigned`, `sla_breached`. */
    abstract public function kind(): string;

    abstract protected function summary(): string;

    /**
     * @param  string  $occurrence  what makes this occurrence unique: a timer id, a comment id, an assignment id
     */
    final public function __construct(
        public readonly string $workspaceName,
        public readonly string $workspaceSlug,
        public readonly string $ticketId,
        public readonly int $ticketNumber,
        public readonly string $ticketTitle,
        public readonly string $occurrence,
    ) {
        $this->onQueue('notifications');
    }

    protected function mailed(): bool
    {
        return false;
    }

    final public function key(): string
    {
        return $this->kind().':'.$this->occurrence;
    }

    /** @return list<string> */
    final public function via(User $notifiable): array
    {
        // No `broadcast` channel: Realtime announces the stored row on the user's channel (M3-16).
        return [TenantDatabaseChannel::class, ...($this->mailed() ? ['mail'] : [])];
    }

    /**
     * @return array{kind: string, ticket_id: string, ticket_number: int, ticket_title: string, summary: string}
     */
    final public function toArray(User $notifiable): array
    {
        return [
            'kind' => $this->kind(),
            'ticket_id' => $this->ticketId,
            'ticket_number' => $this->ticketNumber,
            'ticket_title' => $this->ticketTitle,
            'summary' => $this->summary(),
        ];
    }

    final public function toMail(User $notifiable): MailMessage
    {
        $url = sprintf('https://%s/%s/tickets/%s', config('helpdesk.hosts.app'), $this->workspaceSlug, $this->ticketId);

        $sender = PlatformSender::onBehalfOf($this->workspaceName);
        $thread = TicketThread::for($this->ticketId);

        // MVP-SHORTCUT: workspace name only; the logo needs a public URL; V1: V1-NT-02.
        return (new MailMessage)
            ->from($sender->address, $sender->name)
            ->withSymfonyMessage(fn (Email $message) => $thread->applyNotification($message->getHeaders()))
            ->subject(sprintf('[%s] #%d %s', $this->workspaceName, $this->ticketNumber, $this->summary()))
            ->greeting($this->summary())
            ->line(sprintf('#%d %s', $this->ticketNumber, $this->ticketTitle))
            ->action('Open the ticket', $url)
            ->salutation($this->workspaceName);
    }
}
