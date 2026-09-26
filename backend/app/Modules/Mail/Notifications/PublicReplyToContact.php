<?php

declare(strict_types=1);

namespace App\Modules\Mail\Notifications;

use App\Modules\Mail\Support\MailAttachment;
use App\Modules\Mail\Support\TicketThread;
use App\Modules\Media\Support\MediaStorage;
use App\Modules\Tenancy\Models\Tenant;
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
 *
 * The reply's files are attached: their bytes are read from the workspace's storage when the mail is
 * built (inside the workspace, since media disks are rooted per tenant), and a file that cannot be read
 * or did not fit the size limit is named in the mail instead.
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
        private readonly string $tenantId = '',
        /** @var list<MailAttachment> */
        private readonly array $attachments = [],
        /** @var list<string> names of files left out because of the size limit */
        private readonly array $omitted = [],
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
        [$files, $missing] = $this->readAttachments();

        $message = (new MailMessage)
            ->from($this->senderAddress, $this->senderName)
            ->subject("[#{$this->ticketNumber}] {$this->title}")
            ->replyTo($thread->replyTo(), $this->senderName)
            ->view(['html' => 'mail.ticket-reply', 'text' => 'mail.ticket-reply-text'], [
                'workspaceName' => $this->workspaceName,
                'senderName' => $this->senderName,
                'ticketNumber' => $this->ticketNumber,
                'title' => $this->title,
                'body' => $this->body,
                'attachedNames' => array_map(fn (array $file): string => $file['name'], $files),
                'omittedNames' => [...$this->omitted, ...$missing],
            ])
            ->withSymfonyMessage(function (Email $message) use ($thread): void {
                $thread->applyConversation($message->getHeaders(), $this->sequence, $this->contactId);
            });

        foreach ($files as $file) {
            $message->attachData($file['bytes'], $file['name'], ['mime' => $file['mime']]);
        }

        return $message;
    }

    /**
     * The attachments' bytes, read inside the workspace; files that are gone are returned by name.
     *
     * @return array{list<array{name: string, mime: string, bytes: string}>, list<string>}
     */
    private function readAttachments(): array
    {
        if ($this->attachments === [] || $this->tenantId === '') {
            return [[], []];
        }
        $tenant = Tenant::query()->find($this->tenantId);
        if (! $tenant instanceof Tenant) {
            return [[], array_map(fn (MailAttachment $file): string => $file->name, $this->attachments)];
        }

        return $tenant->run(function (): array {
            $disk = app(MediaStorage::class)->disk();
            $files = [];
            $missing = [];
            foreach ($this->attachments as $file) {
                $bytes = $disk->get($file->storageKey);
                if (is_string($bytes)) {
                    $files[] = ['name' => $file->name, 'mime' => $file->mimeType, 'bytes' => $bytes];
                } else {
                    $missing[] = $file->name;
                }
            }

            return [$files, $missing];
        });
    }
}
