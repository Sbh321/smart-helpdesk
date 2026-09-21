<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Domain;

final readonly class DuplicatePreviewMatch
{
    /** @param list<string> $sharedWords */
    public function __construct(
        public string $ticketId,
        public int $number,
        public string $title,
        public float $score,
        public array $sharedWords,
    ) {}
}
