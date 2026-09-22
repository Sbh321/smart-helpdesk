<?php

declare(strict_types=1);

namespace App\Modules\Mail\Domain;

/** A file carried by an inbound message; `$mime` is what the sender declared and is never trusted. */
final readonly class ParsedAttachment
{
    public function __construct(
        public string $filename,
        public string $mime,
        public string $contents,
        public bool $inline = false,
    ) {}

    public function size(): int
    {
        return strlen($this->contents);
    }
}
