<?php

declare(strict_types=1);

namespace App\Modules\Media\Support;

use App\Modules\Media\Domain\FileInspection;
use finfo;
use ZipArchive;

/**
 * Inspects a file on local disk without loading it into memory: libmagic type, SHA-256,
 * image dimensions, and the OOXML entry that tells a DOCX from a renamed ZIP.
 */
final class FileInspector
{
    public const TYPE_MISMATCH = 'type_mismatch';

    public const UNDECODABLE_IMAGE = 'undecodable_image';

    public const INVALID_DOCUMENT = 'invalid_document';

    public function inspect(string $path, string $extension): FileInspection
    {
        $detected = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if (! is_string($detected) || ! AllowedMedia::acceptsDetected($extension, $detected)) {
            return new FileInspection(self::TYPE_MISMATCH, is_string($detected) ? $detected : '');
        }

        $width = $height = null;
        if (AllowedMedia::isImage($extension)) {
            $dimensions = @getimagesize($path);
            if ($dimensions === false || $dimensions[0] < 1 || $dimensions[1] < 1) {
                return new FileInspection(self::UNDECODABLE_IMAGE, $detected);
            }
            [$width, $height] = [$dimensions[0], $dimensions[1]];
        }

        $entry = AllowedMedia::requiredArchiveEntry($extension);
        if ($entry !== null && ! $this->archiveHolds($path, ['[Content_Types].xml', $entry])) {
            return new FileInspection(self::INVALID_DOCUMENT, $detected);
        }

        $checksum = hash_file('sha256', $path);

        return new FileInspection(null, $detected, $checksum === false ? null : $checksum, $width, $height);
    }

    /**
     * Looks names up in the central directory only; nothing is extracted, so a zip bomb costs nothing.
     *
     * @param  list<string>  $entries
     */
    private function archiveHolds(string $path, array $entries): bool
    {
        $archive = new ZipArchive;
        if ($archive->open($path, ZipArchive::RDONLY) !== true) {
            return false;
        }

        try {
            foreach ($entries as $entry) {
                if ($archive->locateName($entry) === false) {
                    return false;
                }
            }

            return true;
        } finally {
            $archive->close();
        }
    }
}
