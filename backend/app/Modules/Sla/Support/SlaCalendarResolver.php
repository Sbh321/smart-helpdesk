<?php

declare(strict_types=1);

namespace App\Modules\Sla\Support;

use App\Modules\Sla\Contracts\BusinessCalendar as BusinessCalendarContract;
use App\Modules\Sla\Domain\Calendar\TwentyFourSevenCalendar;
use App\Modules\Sla\Domain\Calendar\WorkingHoursCalendar;
use App\Modules\Sla\Models\BusinessCalendar as BusinessCalendarModel;
use App\Support\Time\Clock;
use Carbon\CarbonImmutable;

final readonly class SlaCalendarResolver
{
    public function __construct(private Clock $clock) {}

    public function forId(?string $calendarId): BusinessCalendarContract
    {
        if ($calendarId === null) {
            return new TwentyFourSevenCalendar;
        }

        $stored = BusinessCalendarModel::query()->findOrFail($calendarId);
        $holidays = $stored->holidays->flatMap(function ($holiday): array {
            $date = CarbonImmutable::parse($holiday->date)->format('Y-m-d');
            if (! $holiday->recurs_yearly) {
                return [$date];
            }

            $currentYear = $this->clock->now()->year;
            $month = (int) substr($date, 5, 2);
            $day = (int) substr($date, 8, 2);
            $dates = [];
            foreach (range($currentYear - 1, $currentYear + 10) as $year) {
                if (checkdate($month, $day, $year)) {
                    $dates[] = sprintf('%04d-%02d-%02d', $year, $month, $day);
                }
            }

            return $dates;
        })->all();

        return new WorkingHoursCalendar($stored->timezone, $stored->weekly_hours, $holidays);
    }
}
