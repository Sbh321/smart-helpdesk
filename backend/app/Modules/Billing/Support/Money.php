<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

/** Amounts are integers in minor units (paisa, cents); this writes them for people: "NPR 2,500.00". */
final class Money
{
    public static function format(int $minor, string $currency): string
    {
        return $currency.' '.number_format($minor / 100, 2);
    }
}
