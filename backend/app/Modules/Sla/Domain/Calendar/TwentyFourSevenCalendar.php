<?php

declare(strict_types=1);

namespace App\Modules\Sla\Domain\Calendar;

use App\Modules\Sla\Contracts\BusinessCalendar;
use App\Modules\Sla\Domain\Exceptions\InvalidCalendar;
use Carbon\CarbonImmutable;

/**
 * Every second counts: plain elapsed time, independent of time zones and daylight saving.
 */
final readonly class TwentyFourSevenCalendar implements BusinessCalendar
{
    public function add(CarbonImmutable $start, int $seconds): CarbonImmutable
    {
        if ($seconds < 0) {
            throw new InvalidCalendar('A calendar cannot add a negative duration.');
        }

        return CarbonImmutable::createFromTimestamp($start->getTimestamp() + $seconds, $start->getTimezone());
    }

    public function elapsed(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return max(0, $to->getTimestamp() - $from->getTimestamp());
    }
}
