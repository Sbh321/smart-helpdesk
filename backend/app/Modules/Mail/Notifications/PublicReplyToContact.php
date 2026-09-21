<?php

declare(strict_types=1);

namespace App\Modules\Mail\Notifications;

use App\Modules\Mail\Support\TicketThread;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Symfony\Component\Mime\Email;

/**
 * An agent's public reply, mailed to the requester.
 *
 * The comment body is user input. It is sent as escaped text with line breaks kept; it is never
 * rendered as Markdown or HTML, so a reply cannot inject links, images or markup into the mail
 * (docs/03-architecture/security.md). Only primitives are carried, so the queued job needs no
 * tenant context.
 */
final class PublicReplyToContact extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $workspaceName,
        private readonly string $ticketId,
        private readonly int $ticketNumber,
        private readonly string $title,
        private readonly string $body,
        private readonly string $contactId,
        private readonly int $sequence,
    ) {
        $this->onQueue('notifications');
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $thread = new TicketThread($this->ticketId);

        return (new MailMessage)
            ->subject("[#{$this->ticketNumber}] {$this->title}")
            ->replyTo($thread->replyTo(), "{$this->workspaceName} support")
            ->view(['html' => 'mail.ticket-reply', 'text' => 'mail.ticket-reply-text'], [
                'workspaceName' => $this->workspaceName,
                'ticketNumber' => $this->ticketNumber,
                'title' => $this->title,
                'body' => $this->body,
            ])
            ->withSymfonyMessage(function (Email $message) use ($thread): void {
                $headers = $message->getHeaders();
                $headers->remove('Message-ID');
                $headers->addIdHeader('Message-ID', $thread->messageId($this->sequence));

                if ($this->sequence > 0) {
                    $headers->addIdHeader('In-Reply-To', $thread->messageId($this->sequence - 1));
                    $headers->addIdHeader('References', array_values(array_unique([
                        $thread->messageId(0),
                        $thread->messageId($this->sequence - 1),
                    ])));
                }

                $headers->addTextHeader('List-Unsubscribe', $thread->unsubscribe($this->contactId));
                $headers->addTextHeader('X-Helpdesk-Ticket', $this->ticketId);
            });
    }
}
