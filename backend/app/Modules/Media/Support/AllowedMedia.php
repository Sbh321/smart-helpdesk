<?php

declare(strict_types=1);

namespace App\Modules\Media\Support;

/**
 * The upload allow-list (docs/03-architecture/storage.md §Validation).
 *
 * `helpdesk.media.allowed_mime` switches canonical types on and off and
 * `helpdesk.media.max_file_bytes` is the size limit; this class only adds what configuration
 * cannot express: which extension belongs to a type, which values browsers DECLARE for it, and
 * which values libmagic DETECTS for it. The declared type is advisory (it only has to be
 * plausible); the detected type decides. SVG, HTML and scripts have no row and cannot be enabled
 * from configuration.
 */
final class AllowedMedia
{
    /** The database CHECK on media_items.size_bytes; configuration can lower the limit, not raise it. */
    public const HARD_MAX_BYTES = 26214400;

    private const OOXML = 'application/vnd.openxmlformats-officedocument.';

    private const GENERIC = ['', 'application/octet-stream'];

    private const ZIP = ['application/zip', 'application/x-zip-compressed', 'application/x-zip'];

    private const TEXT = ['text/plain', 'text/csv', 'application/csv'];

    /** @var array<string, array{mime: string, declared: list<string>, detected: list<string>, entry?: string}> */
    private const TYPES = [
        'png' => ['mime' => 'image/png', 'declared' => ['image/png'], 'detected' => ['image/png']],
        'jpg' => ['mime' => 'image/jpeg', 'declared' => ['image/jpeg', 'image/pjpeg'], 'detected' => ['image/jpeg']],
        'jpeg' => ['mime' => 'image/jpeg', 'declared' => ['image/jpeg', 'image/pjpeg'], 'detected' => ['image/jpeg']],
        'gif' => ['mime' => 'image/gif', 'declared' => ['image/gif'], 'detected' => ['image/gif']],
        'webp' => ['mime' => 'image/webp', 'declared' => ['image/webp'], 'detected' => ['image/webp']],
        'pdf' => ['mime' => 'application/pdf', 'declared' => ['application/pdf', ...self::GENERIC], 'detected' => ['application/pdf']],
        'txt' => ['mime' => 'text/plain', 'declared' => ['text/plain', ...self::GENERIC], 'detected' => self::TEXT],
        'log' => ['mime' => 'text/plain', 'declared' => ['text/plain', 'text/x-log', ...self::GENERIC], 'detected' => self::TEXT],
        'csv' => [
            'mime' => 'text/csv',
            'declared' => ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel', ...self::GENERIC],
            'detected' => self::TEXT,
        ],
        'zip' => ['mime' => 'application/zip', 'declared' => [...self::ZIP, ...self::GENERIC], 'detected' => ['application/zip']],
        'docx' => [
            'mime' => self::OOXML.'wordprocessingml.document',
            'declared' => [self::OOXML.'wordprocessingml.document', ...self::ZIP, ...self::GENERIC],
            'detected' => [self::OOXML.'wordprocessingml.document', 'application/zip', 'application/octet-stream'],
            'entry' => 'word/document.xml',
        ],
        'xlsx' => [
            'mime' => self::OOXML.'spreadsheetml.sheet',
            'declared' => [self::OOXML.'spreadsheetml.sheet', ...self::ZIP, ...self::GENERIC],
            'detected' => [self::OOXML.'spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
            'entry' => 'xl/workbook.xml',
        ],
        'pptx' => [
            'mime' => self::OOXML.'presentationml.presentation',
            'declared' => [self::OOXML.'presentationml.presentation', ...self::ZIP, ...self::GENERIC],
            'detected' => [self::OOXML.'presentationml.presentation', 'application/zip', 'application/octet-stream'],
            'entry' => 'ppt/presentation.xml',
        ],
    ];

    public static function maxBytes(): int
    {
        $configured = (int) config('helpdesk.media.max_file_bytes', self::HARD_MAX_BYTES);

        return max(1, min($configured, self::HARD_MAX_BYTES));
    }

    /** The allow-listed extension of a filename, or null when the type is not accepted. */
    public static function extensionFor(string $filename): ?string
    {
        $extension = MediaFilename::extension($filename);
        $type = self::TYPES[$extension] ?? null;
        if ($type === null) {
            return null;
        }

        /** @var list<string> $enabled */
        $enabled = (array) config('helpdesk.media.allowed_mime', []);

        return in_array($type['mime'], $enabled, true) ? $extension : null;
    }

    /** The type stored on the item and signed into the upload URL. */
    public static function mimeFor(string $extension): string
    {
        return self::TYPES[$extension]['mime'];
    }

    /** Whether a browser plausibly declares this type for the extension (advisory only). */
    public static function acceptsDeclared(string $extension, string $declared): bool
    {
        $declared = strtolower(trim(explode(';', $declared)[0]));

        return in_array($declared, self::TYPES[$extension]['declared'] ?? [], true);
    }

    /** Whether the type libmagic found in the bytes is acceptable for the extension. */
    public static function acceptsDetected(string $extension, string $detected): bool
    {
        return in_array($detected, self::TYPES[$extension]['detected'] ?? [], true);
    }

    /** The archive entry an OOXML container must hold, null for every other type. */
    public static function requiredArchiveEntry(string $extension): ?string
    {
        return self::TYPES[$extension]['entry'] ?? null;
    }

    public static function isImage(string $extension): bool
    {
        return str_starts_with(self::TYPES[$extension]['mime'] ?? '', 'image/');
    }
}
