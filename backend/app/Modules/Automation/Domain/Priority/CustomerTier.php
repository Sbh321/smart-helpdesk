<?php

declare(strict_types=1);

namespace App\Modules\Automation\Domain\Priority;

/**
 * Customer tier of the ticket's organisation, scaled to 0–1 for scoring.
 */
enum CustomerTier: string
{
    case Standard = 'standard';
    case Premium = 'premium';
    case Enterprise = 'enterprise';

    public function scaled(): float
    {
        return match ($this) {
            self::Standard => 0.0,
            self::Premium => 0.5,
            self::Enterprise => 1.0,
        };
    }
}
