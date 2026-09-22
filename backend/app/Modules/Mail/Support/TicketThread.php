<?php

declare(strict_types=1);

namespace App\Modules\Mail\Support;

use Symfony\Component\Mime\Header\Headers;

/**
 * Addresses, message ids and headers that thread ticket mail (docs/04-domain/email.md §Outbound).
 *
 * Every mail of a ticket references the same root id, so mail clients group them, and replies go
 * to the plus-address that inbound routing resolves back to the ticket. Pure: the mail domain is
 * passed in (`for()` reads it from configuration), so the header builder is unit-tested.
 */
final readonly class TicketThread
{
    public function __construct(private string $ticketId, private string $domain) {}

    /** The thread of a ticket on the configured mail domain (`helpdesk.hosts.mail`). */
    public static function for(string $ticketId): self
    {
        return new self($ticketId, (string) config('helpdesk.hosts.mail'));
    }

    public function replyTo(): string
    {
        return sprintf('ticket+%s@%s', $this->ticketId, $this->domain);
    }

    /**
     * Message id without angle brackets; `$sequence` 0 is the thread root.
     */
    public function messageId(int $sequence): string
    {
        return sprintf('ticket-%s.%d@%s', $this->ticketId, max(0, $sequence), $this->domain);
    }

    public function unsubscribe(string $contactId): string
    {
        return sprintf('<mailto:unsubscribe+%s@%s>', $contactId, $this->domain);
    }

    /**
     * Headers of a mail that is part of the conversation with the requester: its own `Message-ID`
     * (position `$sequence` in the thread), `In-Reply-To` the previous mail and `References` the root
     * and the previous mail, and `List-Unsubscribe` when it goes to a contact. The first reply
     * (sequence 0) is the root and references nothing.
     */
    public function applyConversation(Headers $headers, int $sequence, ?string $contactId = null): void
    {
        $sequence = max(0, $sequence);
        $headers->remove('Message-ID');
        $headers->addIdHeader('Message-ID', $this->messageId($sequence));

        foreach (['In-Reply-To', 'References'] as $name) {
            $headers->remove($name);
        }
        if ($sequence > 0) {
            $headers->addIdHeader('In-Reply-To', $this->messageId($sequence - 1));
            $headers->addIdHeader('References', array_values(array_unique([
                $this->messageId(0),
                $this->messageId($sequence - 1),
            ])));
        }

        $headers->remove('List-Unsubscribe');
        // MVP-SHORTCUT: mailto only, nothing processes it yet and no one-click List-Unsubscribe-Post; V1: V1-ML-04.
        if ($contactId !== null) {
            $headers->addTextHeader('List-Unsubscribe', $this->unsubscribe($contactId));
        }

        $this->tag($headers);
    }

    /**
     * Headers of a notification about the ticket (to an agent): it keeps its own generated
     * `Message-ID`, references the thread root so clients group it with the ticket, and says it was
     * generated automatically (RFC 3834), so auto-responders do not answer it.
     */
    public function applyNotification(Headers $headers): void
    {
        $headers->remove('References');
        $headers->addIdHeader('References', [$this->messageId(0)]);
        $headers->remove('Auto-Submitted');
        $headers->addTextHeader('Auto-Submitted', 'auto-generated');

        $this->tag($headers);
    }

    private function tag(Headers $headers): void
    {
        $headers->remove('X-Helpdesk-Ticket');
        $headers->addTextHeader('X-Helpdesk-Ticket', $this->ticketId);
    }
}
