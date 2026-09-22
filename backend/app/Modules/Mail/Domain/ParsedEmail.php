<?php

declare(strict_types=1);

namespace App\Modules\Mail\Domain;

use DateTimeImmutable;

/**
 * An inbound message after MIME decoding (docs/04-domain/email.md §Inbound pipeline). Built by
 * `Support\MimeParser`; everything that decides what to do with the message reads this value only.
 */
final readonly class ParsedEmail
{
    /**
     * @param  array<string, list<string>>  $headers  raw header values by lower-case name, in order
     * @param  list<EmailAddress>  $to
     * @param  list<EmailAddress>  $cc
     * @param  list<string>  $inReplyTo  message ids without angle brackets
     * @param  list<string>  $references  message ids without angle brackets, oldest first
     * @param  list<ParsedAttachment>  $attachments
     */
    public function __construct(
        public ?string $messageId,
        public ?EmailAddress $from,
        public array $to,
        public array $cc,
        public string $subject,
        public ?DateTimeImmutable $sentAt,
        public array $inReplyTo,
        public array $references,
        public array $headers,
        public ?string $text,
        public ?string $html,
        public array $attachments = [],
    ) {}

    /**
     * @return list<string>
     */
    public function header(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    public function firstHeader(string $name): ?string
    {
        return $this->header($name)[0] ?? null;
    }

    /**
     * Every address the message was delivered to. The catch-all rewrites the envelope recipient to
     * `inbound@`, so the headers carry the address the sender used: To, Cc, and the Delivered-To and
     * X-Original-To lines a server may add.
     *
     * @return list<string>
     */
    public function recipients(): array
    {
        $addresses = array_map(fn (EmailAddress $address): string => $address->address, [...$this->to, ...$this->cc]);

        foreach ([...$this->header('delivered-to'), ...$this->header('x-original-to')] as $value) {
            preg_match_all('/[^\s<>,;"]+@[^\s<>,;"]+/', $value, $matches);
            foreach ($matches[0] as $address) {
                $addresses[] = strtolower($address);
            }
        }

        return array_values(array_unique($addresses));
    }
}
