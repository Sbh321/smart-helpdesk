<?php

declare(strict_types=1);

namespace App\Modules\Media\Domain;

/** What completion learned about an uploaded file; `rejection` is null when the file is accepted. */
final readonly class FileInspection
{
    public function __construct(
        public ?string $rejection,
        public string $detectedMime = '',
        public ?string $checksumSha256 = null,
        public ?int $width = null,
        public ?int $height = null,
    ) {}

    public function accepted(): bool
    {
        return $this->rejection === null;
    }
}
