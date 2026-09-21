<?php

declare(strict_types=1);

namespace App\Modules\Mail\Support;

/**
 * Addresses and message ids that thread ticket mail (docs/04-domain/email.md §Outbound).
 *
 * Every mail of a ticket references the same root id, so mail clients group them, and replies go
 * to the plus-address that inbound routing resolves back to the ticket.
 */
final readonly class TicketThread
{
    public function __construct(private string $ticketId) {}

    public function replyTo(): string
    {
        return sprintf('ticket+%s@%s', $this->ticketId, $this->domain());
    }

    /**
     * Message id without angle brackets; `$sequence` 0 is the thread root.
     */
    public function messageId(int $sequence): string
    {
        return sprintf('ticket-%s.%d@%s', $this->ticketId, max(0, $sequence), $this->domain());
    }

    public function unsubscribe(string $contactId): string
    {
        return sprintf('<mailto:unsubscribe+%s@%s>', $contactId, $this->domain());
    }

    private function domain(): string
    {
        return (string) config('helpdesk.hosts.mail');
    }
}
