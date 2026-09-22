<?php

declare(strict_types=1);

namespace App\Modules\Mail\Domain;

/**
 * Turns an email subject into a ticket title: reply and forward prefixes (`Re:`, `Fwd:`, `FW:`,
 * `AW:`, `SV:`, repeated or bracketed as `Re[2]:`) and our own `[#1042]` tag are removed, white space
 * is collapsed, and an empty result becomes "(no subject)". At most 200 characters, the title limit.
 */
final class SubjectLine
{
    private const int MAX = 200;

    public static function toTitle(string $subject): string
    {
        $title = trim((string) preg_replace('/\s+/u', ' ', $subject));

        do {
            $before = $title;
            $title = trim((string) preg_replace('/^(?:(?:re|fwd?|aw|sv)(?:\[\d+\])?\s*:|\[#\d+\])\s*/iu', '', $title));
        } while ($title !== $before);

        return $title === '' ? '(no subject)' : mb_substr($title, 0, self::MAX);
    }
}
