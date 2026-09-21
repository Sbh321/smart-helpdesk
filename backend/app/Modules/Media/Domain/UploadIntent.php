<?php

declare(strict_types=1);

namespace App\Modules\Media\Domain;

final readonly class UploadIntent
{
    /** @param array<string, string> $headers */
    public function __construct(
        public string $media_id,
        public string $url,
        public array $headers,
    ) {}
}
