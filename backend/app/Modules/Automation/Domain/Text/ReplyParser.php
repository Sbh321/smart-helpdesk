<?php

declare(strict_types=1);

namespace App\Modules\Automation\Domain\Text;

/**
 * Keeps only the new text of an inbound email reply by cutting at the first quote marker or signature
 * separator (ADR-0023; docs/04-domain/email.md). The original message is stored separately for audit.
 *
 * Markers, checked line by line from the top:
 * - a line starting with `>` (quoted text);
 * - `On … wrote:` (Gmail, Apple Mail), also when the client wrapped it over up to three lines;
 * - `-----Original Message-----` (Outlook plain text) or a line of underscores (Outlook HTML converted to text);
 * - a `From:` line after a blank line that is followed by another header (`Sent:`, `Date:`, `To:`, `Subject:`);
 * - the signature separator `-- ` (also without the trailing space).
 */
final class ReplyParser
{
    private const int WRAPPED_LINES = 3;

    public function parse(string $body): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $body));
        $cut = $this->firstMarker($lines);
        $kept = $cut === null ? $lines : array_slice($lines, 0, $cut);
        $reply = trim(implode("\n", array_map(rtrim(...), $kept)));

        if ($reply === '' && $cut !== null) {
            // Bottom-posted reply: nothing above the first marker, so drop only the quoted lines.
            $unquoted = array_filter($lines, static fn (string $line): bool => ! str_starts_with(ltrim($line), '>'));
            $reply = trim(implode("\n", array_map(rtrim(...), $unquoted)));
        }

        return $reply;
    }

    /**
     * @param  list<string>  $lines
     */
    private function firstMarker(array $lines): ?int
    {
        foreach ($lines as $index => $line) {
            if ($this->isMarker($lines, $index, $line)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $lines
     */
    private function isMarker(array $lines, int $index, string $line): bool
    {
        $trimmed = trim($line);

        return str_starts_with(ltrim($line), '>')
            || rtrim($line, " \t") === '--'
            || preg_match('/^-{3,}\s*original message\s*-{3,}$/i', $trimmed) === 1
            || preg_match('/^_{10,}$/', $trimmed) === 1
            || $this->isAttribution($lines, $index)
            || $this->isHeaderBlock($lines, $index);
    }

    /**
     * @param  list<string>  $lines
     */
    private function isAttribution(array $lines, int $index): bool
    {
        if (! str_starts_with(trim($lines[$index]), 'On ')) {
            return false;
        }

        $joined = '';

        for ($offset = 0; $offset < self::WRAPPED_LINES && isset($lines[$index + $offset]); $offset++) {
            $part = trim($lines[$index + $offset]);

            if ($part === '') {
                return false;
            }

            $joined = trim($joined.' '.$part);

            if (preg_match('/^On .+ wrote:$/', $joined) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $lines
     */
    private function isHeaderBlock(array $lines, int $index): bool
    {
        if (! str_starts_with($lines[$index], 'From: ') || ($index > 0 && trim($lines[$index - 1]) !== '')) {
            return false;
        }

        for ($offset = 1; $offset <= 4 && isset($lines[$index + $offset]); $offset++) {
            if (preg_match('/^(Sent|Date|To|Cc|Subject): /', $lines[$index + $offset]) === 1) {
                return true;
            }
        }

        return false;
    }
}
