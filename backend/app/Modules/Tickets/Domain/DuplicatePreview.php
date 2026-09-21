<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Domain;

final readonly class DuplicatePreview
{
    /** @param list<DuplicatePreviewMatch> $matches */
    public function __construct(
        public string $strategy,
        public string $strategyVersion,
        public int $candidatesCompared,
        public array $matches,
    ) {}
}
