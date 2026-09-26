<?php

declare(strict_types=1);

namespace App\Modules\Mail\Support;

use App\Modules\Media\Models\MediaItem;

/**
 * A file of a public reply, carried to the queued mail as primitives: the storage key is read inside the
 * workspace when the mail is built.
 */
final readonly class MailAttachment
{
    public function __construct(
        public string $storageKey,
        public string $name,
        public string $mimeType,
        public int $sizeBytes,
    ) {}

    public static function of(MediaItem $item): self
    {
        return new self($item->storage_key, $item->name, $item->mime_type, $item->size_bytes);
    }

    /**
     * Splits files into those that fit the mail (in order, up to `$maxBytes` in total) and the rest.
     *
     * @param  list<self>  $files
     * @return array{list<self>, list<self>}
     */
    public static function fit(array $files, int $maxBytes): array
    {
        $attached = [];
        $omitted = [];
        $total = 0;
        foreach ($files as $file) {
            if ($total + $file->sizeBytes <= $maxBytes) {
                $attached[] = $file;
                $total += $file->sizeBytes;
            } else {
                $omitted[] = $file;
            }
        }

        return [$attached, $omitted];
    }
}
