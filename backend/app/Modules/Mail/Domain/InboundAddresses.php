<?php

declare(strict_types=1);

namespace App\Modules\Mail\Domain;

/**
 * Reads the platform's own addresses back (docs/04-domain/email.md §Addresses and identities):
 * `ticket+<uuid>@<domain>` (Reply-To of ticket mail), `support+<slug>@<domain>` (intake) and the
 * Message-IDs `ticket-<uuid>.<n>@<domain>` that `TicketThread` gives ticket mail. Anything on another
 * domain, or not in exactly this shape, is not ours.
 */
final readonly class InboundAddresses
{
    private const string UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    private const string SLUG = '[a-z0-9](?:[a-z0-9-]{0,62})';

    private string $domain;

    public function __construct(string $domain)
    {
        $this->domain = preg_quote(strtolower(trim($domain)), '/');
    }

    public function ticketId(string $address): ?string
    {
        return $this->match('/^ticket\+('.self::UUID.')@'.$this->domain.'$/', $address);
    }

    public function workspaceSlug(string $address): ?string
    {
        return $this->match('/^support\+('.self::SLUG.')@'.$this->domain.'$/', $address);
    }

    public function ticketIdFromMessageId(string $messageId): ?string
    {
        return $this->match('/^<?ticket-('.self::UUID.')\.\d{1,6}@'.$this->domain.'>?$/', $messageId);
    }

    public function candidates(ParsedEmail $email): RouteCandidates
    {
        $recipients = $email->recipients();
        $threads = [...$email->inReplyTo, ...array_reverse($email->references)];

        return new RouteCandidates(
            $this->collect($recipients, $this->ticketId(...)),
            $this->collect($threads, $this->ticketIdFromMessageId(...)),
            $this->collect($recipients, $this->workspaceSlug(...)),
        );
    }

    /**
     * @param  list<string>  $values
     * @param  callable(string): ?string  $reader
     * @return list<string>
     */
    private function collect(array $values, callable $reader): array
    {
        $found = [];
        foreach ($values as $value) {
            $match = $reader($value);
            if ($match !== null && ! in_array($match, $found, true)) {
                $found[] = $match;
            }
        }

        return $found;
    }

    private function match(string $pattern, string $value): ?string
    {
        return preg_match($pattern, strtolower(trim($value)), $matches) === 1 ? $matches[1] : null;
    }
}
