<?php

declare(strict_types=1);

namespace App\Modules\Mail\Domain;

/**
 * Splits a raw header block into values by lower-case name (RFC 5322 §2.2: a line starting with
 * white space continues the previous field). Values stay undecoded; they are kept for audit and for
 * the automated-mail checks, which only compare ASCII tokens.
 */
final class RawHeaders
{
    /**
     * @return array<string, list<string>>
     */
    public static function parse(string $message): array
    {
        $normalised = str_replace(["\r\n", "\r"], "\n", $message);
        $end = strpos($normalised, "\n\n");
        $block = $end === false ? $normalised : substr($normalised, 0, $end);
        $headers = [];
        $name = null;

        foreach (explode("\n", $block) as $line) {
            if ($line === '') {
                continue;
            }

            if ($name !== null && ($line[0] === ' ' || $line[0] === "\t")) {
                $last = array_key_last($headers[$name]);
                $headers[$name][$last] .= ' '.trim($line);

                continue;
            }

            $colon = strpos($line, ':');
            if ($colon === false || $colon === 0) {
                $name = null;

                continue;
            }

            $name = strtolower(trim(substr($line, 0, $colon)));
            $headers[$name][] = trim(substr($line, $colon + 1));
        }

        return $headers;
    }
}
