<?php

declare(strict_types=1);

namespace App\Modules\Mail\Support;

use App\Modules\Mail\Domain\EmailAddress;
use App\Modules\Mail\Domain\ParsedAttachment;
use App\Modules\Mail\Domain\ParsedEmail;
use App\Modules\Mail\Domain\RawHeaders;
use DateTimeImmutable;
use Throwable;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Attachment;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Message;

/**
 * Decodes a raw RFC 5322 message into a `ParsedEmail` with webklex/php-imap (bodies, charsets,
 * encoded words, attachments). The IMAP client and the fixtures feed it the same raw bytes, so
 * the tests parse exactly what production parses.
 */
final class MimeParser
{
    public function parse(string $raw): ParsedEmail
    {
        // One line ending throughout: a message whose server-added headers end in CRLF but whose own
        // lines end in LF would otherwise lose its header block in Message::fromString().
        $raw = (string) preg_replace("/\r?\n/", "\r\n", $raw);
        // The package defaults (masks, decoders); a broken Date header must not lose the message, so
        // the package gets a fallback date and `sentAt` comes from our own reading of the header.
        $config = (new ClientManager(['options' => ['fallback_date' => '1970-01-01 00:00:00']]))->getConfig();
        $message = Message::fromString($raw, $config);
        $headers = RawHeaders::parse($raw);

        return new ParsedEmail(
            messageId: $this->messageId((string) $message->getMessageId()),
            from: $this->addresses($message, 'from')[0] ?? null,
            to: $this->addresses($message, 'to'),
            cc: $this->addresses($message, 'cc'),
            subject: $this->text((string) $message->getSubject()),
            sentAt: $this->date($headers['date'][0] ?? null),
            inReplyTo: $this->messageIds($headers['in-reply-to'] ?? []),
            references: $this->messageIds($headers['references'] ?? []),
            headers: $headers,
            text: $message->hasTextBody() ? $this->text($message->getTextBody()) : null,
            html: $message->hasHTMLBody() ? $message->getHTMLBody() : null,
            attachments: $this->attachments($message),
        );
    }

    /**
     * @return list<EmailAddress>
     */
    private function addresses(Message $message, string $field): array
    {
        $attribute = $message->getHeader()?->get($field);
        $addresses = [];

        foreach ($attribute?->all() ?? [] as $address) {
            if ($address instanceof Address && str_contains((string) $address->mail, '@')) {
                $name = trim(trim((string) $address->personal), '"\'');
                $addresses[] = new EmailAddress((string) $address->mail, $name === '' ? null : $name);
            }
        }

        return $addresses;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function messageIds(array $values): array
    {
        preg_match_all('/<([^<>\s]+)>/', implode(' ', $values), $matches);

        return array_values(array_unique($matches[1]));
    }

    private function messageId(string $value): ?string
    {
        $value = trim($value, " \t<>");

        return $value === '' ? null : mb_substr($value, 0, 998);
    }

    private function date(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        try {
            return new DateTimeImmutable((string) preg_replace('/\s*\([^)]*\)\s*$/', '', $value));
        } catch (Throwable) {
            return null;
        }
    }

    /** Valid UTF-8 without NUL bytes, which PostgreSQL text columns refuse. */
    private function text(string $value): string
    {
        $value = mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'UTF-8');

        return str_replace("\0", '', $value);
    }

    /**
     * @return list<ParsedAttachment>
     */
    private function attachments(Message $message): array
    {
        $attachments = [];

        foreach ($message->getAttachments() as $attachment) {
            if (! $attachment instanceof Attachment) {
                continue;
            }

            $name = trim((string) ($attachment->filename ?: $attachment->name));
            $attachments[] = new ParsedAttachment(
                $name === '' ? 'attachment' : $this->text($name),
                strtolower((string) $attachment->content_type),
                (string) $attachment->content,
                strtolower((string) $attachment->disposition) === 'inline',
            );
        }

        return $attachments;
    }
}
