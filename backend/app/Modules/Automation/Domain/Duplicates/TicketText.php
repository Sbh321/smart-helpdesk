<?php

declare(strict_types=1);

namespace App\Modules\Automation\Domain\Duplicates;

use DateTimeInterface;

/**
 * The text of a ticket as compared by the duplicate strategy.
 */
final readonly class TicketText
{
    public function __construct(
        public string $id,
        public string $title,
        public string $description = '',
        public ?DateTimeInterface $createdAt = null,
    ) {}
}
