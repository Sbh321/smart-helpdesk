<?php

declare(strict_types=1);

namespace App\Modules\Media\Support;

use InvalidArgumentException;

/**
 * Server-generated, tenant-relative object keys (docs/03-architecture/storage.md §Key layout).
 * Nothing a client sends ever reaches a key: the id is ours and the extension is allow-listed.
 */
final class MediaKeys
{
    public const STAGING_PREFIX = 'uploads';

    public const VARIANTS = ['thumb', 'preview'];

    /** Where the presigned PUT writes; the object only becomes a media object after verification. */
    public static function staging(string $id, string $extension): string
    {
        return self::STAGING_PREFIX.'/'.self::id($id).'.'.self::extension($extension);
    }

    public static function original(string $id, string $extension): string
    {
        return 'media/'.self::id($id).'/original.'.self::extension($extension);
    }

    public static function variant(string $id, string $name): string
    {
        if (! in_array($name, self::VARIANTS, true)) {
            throw new InvalidArgumentException("Unknown variant [{$name}].");
        }

        return 'media/'.self::id($id)."/{$name}.webp";
    }

    /** The staging key that belongs to a stored original key. */
    public static function stagingFor(string $id, string $originalKey): string
    {
        return self::staging($id, pathinfo($originalKey, PATHINFO_EXTENSION));
    }

    private static function id(string $id): string
    {
        if (preg_match('/\A[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/', $id) !== 1) {
            throw new InvalidArgumentException('Media keys are built from UUIDs only.');
        }

        return $id;
    }

    private static function extension(string $extension): string
    {
        if (preg_match('/\A[a-z0-9]{1,5}\z/', $extension) !== 1) {
            throw new InvalidArgumentException('Media keys use short lower-case extensions only.');
        }

        return $extension;
    }
}
