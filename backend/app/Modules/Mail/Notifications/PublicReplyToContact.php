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
 * tenant context: the sender (the workspace's mail identity) is resolved by the listener.
 */
final class PublicReplyToContact extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $workspaceName,
        private readonly string $senderName,
        private readonly string $senderAddress,
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
        $thread = TicketThread::for($this->ticketId);

        return (new MailMessage)
            ->from($this->senderAddress, $this->senderName)
            ->subject("[#{$this->ticketNumber}] {$this->title}")
            ->replyTo($thread->replyTo(), $this->senderName)
            ->view(['html' => 'mail.ticket-reply', 'text' => 'mail.ticket-reply-text'], [
                'workspaceName' => $this->workspaceName,
                'senderName' => $this->senderName,
                'ticketNumber' => $this->ticketNumber,
                'title' => $this->title,
                'body' => $this->body,
            ])
            ->withSymfonyMessage(function (Email $message) use ($thread): void {
                $thread->applyConversation($message->getHeaders(), $this->sequence, $this->contactId);
            });
    }
}
