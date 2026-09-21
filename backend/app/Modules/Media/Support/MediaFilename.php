<?php

declare(strict_types=1);

namespace App\Modules\Media\Support;

use Illuminate\Support\Str;

/**
 * The one filename sanitiser: upload intent and rename both store its output, and downloads
 * build `Content-Disposition` from it (docs/03-architecture/security.md §Uploads).
 */
final class MediaFilename
{
    public const MAX_LENGTH = 255;

    /**
     * A display name without path parts, control characters or characters that break headers
     * and common filesystems. Returns '' when nothing usable is left.
     */
    public static function sanitise(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        // Invalid UTF-8 would make preg_replace return null.
        $name = mb_scrub($name, 'UTF-8');
        $name = (string) preg_replace('/[\x00-\x1F\x7F"<>:|?*;\/%]+/u', '_', $name);
        // Bidirectional overrides can disguise an extension ("exe.png" shown as "gnp.exe").
        $name = (string) preg_replace('/[\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200E}\x{200F}]/u', '', $name);
        $name = trim((string) preg_replace('/\s+/u', ' ', $name), " .\t");

        if (mb_strlen($name) > self::MAX_LENGTH) {
            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $suffix = $extension === '' ? '' : '.'.$extension;
            $name = mb_substr($name, 0, self::MAX_LENGTH - mb_strlen($suffix)).$suffix;
        }

        return $name;
    }

    /** Lower-case extension of a name, '' when there is none. */
    public static function extension(string $name): string
    {
        return strtolower(pathinfo($name, PATHINFO_EXTENSION));
    }

    /** RFC 6266: an ASCII `filename` fallback plus the exact name as RFC 5987 `filename*`. */
    public static function contentDisposition(string $name): string
    {
        $name = self::sanitise($name);
        if ($name === '') {
            $name = 'download';
        }

        $ascii = (string) preg_replace('/[^\x20-\x7E]/', '_', Str::ascii($name));
        $ascii = trim(str_replace(['"', '\\'], '_', $ascii));
        // Nothing readable left before the extension ("日本.pdf" → ".pdf"): a dotfile is no fallback.
        if (trim(pathinfo($ascii, PATHINFO_FILENAME), '_. ') === '') {
            $extension = self::extension($name);
            $ascii = 'download'.($extension === '' || preg_match('/\A[a-z0-9]+\z/', $extension) !== 1 ? '' : '.'.$extension);
        }

        return sprintf('attachment; filename="%s"; filename*=UTF-8\'\'%s', $ascii, rawurlencode($name));
    }
}
