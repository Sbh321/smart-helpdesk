<?php

declare(strict_types=1);

namespace App\Modules\Sla\Domain\Calendar;

use App\Modules\Sla\Contracts\BusinessCalendar;
use App\Modules\Sla\Domain\Exceptions\InvalidCalendar;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Counts only the tenant's working hours: weekly windows in an IANA time zone, minus holidays.
 * Works day by day on real instants, so days with a daylight-saving change have their true length.
 */
final readonly class WorkingHoursCalendar implements BusinessCalendar
{
    public const array DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /** Upper bound of days walked in one call (ten years), so a calendar without open days cannot loop forever. */
    private const int MAX_DAYS = 3660;

    public DateTimeZone $timezone;

    /** @var array<string, list<array{int, int}>> weekday => windows as [start minute, end minute] */
    private array $windows;

    /** @var array<string, true> */
    private array $holidays;

    /**
     * @param  array<string, list<array{string, string}>>  $weeklyHours  e.g. ['sun' => [['10:00', '17:00']]]; end may be '24:00'
     * @param  list<string>  $holidays  dates as Y-m-d in the calendar's time zone
     */
    public function __construct(string $timezone, array $weeklyHours, array $holidays = [])
    {
        try {
            $this->timezone = new DateTimeZone($timezone);
        } catch (Throwable) {
            throw new InvalidCalendar("Unknown time zone {$timezone}.");
        }

        $this->windows = self::normaliseWindows($weeklyHours);
        $this->holidays = self::normaliseHolidays($holidays);
    }

    public function add(CarbonImmutable $start, int $seconds): CarbonImmutable
    {
        if ($seconds < 0) {
            throw new InvalidCalendar('A calendar cannot add a negative duration.');
        }

        if ($seconds === 0) {
            return $start;
        }

        $left = $seconds;
        $cursor = $start->getTimestamp();
        $day = $this->localDay($start);

        for ($walked = 0; $walked < self::MAX_DAYS; $walked++) {
            foreach ($this->segments($day) as [$open, $close]) {
                if ($close <= $cursor) {
                    continue;
                }

                $from = max($cursor, $open);
                $free = $close - $from;

                if ($left <= $free) {
                    return CarbonImmutable::createFromTimestamp($from + $left, $start->getTimezone());
                }

                $left -= $free;
            }

            $day = $this->nextDay($day);
        }

        throw new InvalidCalendar('The calendar has no working time left within ten years.');
    }

    public function elapsed(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $start = $from->getTimestamp();
        $end = $to->getTimestamp();
        $total = 0;

        for ($day = $this->localDay($from); $day->getTimestamp() < $end; $day = $this->nextDay($day)) {
            foreach ($this->segments($day) as [$open, $close]) {
                $total += max(0, min($close, $end) - max($open, $start));
            }
        }

        return $total;
    }

    public function isHoliday(CarbonImmutable $instant): bool
    {
        return isset($this->holidays[$instant->setTimezone($this->timezone)->format('Y-m-d')]);
    }

    /**
     * Working windows of one local day as [open, close] Unix timestamps.
     *
     * @return list<array{int, int}>
     */
    private function segments(CarbonImmutable $day): array
    {
        if (isset($this->holidays[$day->format('Y-m-d')])) {
            return [];
        }

        $segments = [];

        foreach ($this->windows[strtolower($day->format('D'))] ?? [] as [$startMinute, $endMinute]) {
            $segments[] = [$this->at($day, $startMinute)->getTimestamp(), $this->at($day, $endMinute)->getTimestamp()];
        }

        return $segments;
    }

    private function at(CarbonImmutable $day, int $minute): CarbonImmutable
    {
        return $minute === 1440 ? $this->nextDay($day) : $day->setTime(intdiv($minute, 60), $minute % 60);
    }

    private function localDay(CarbonImmutable $instant): CarbonImmutable
    {
        return $instant->setTimezone($this->timezone)->startOfDay();
    }

    private function nextDay(CarbonImmutable $day): CarbonImmutable
    {
        return $day->addDay()->startOfDay();
    }

    /**
     * @param  array<string, list<array{string, string}>>  $weeklyHours
     * @return array<string, list<array{int, int}>>
     */
    private static function normaliseWindows(array $weeklyHours): array
    {
        $windows = [];

        foreach ($weeklyHours as $day => $dayWindows) {
            if (! in_array($day, self::DAYS, true)) {
                throw new InvalidCalendar("Unknown weekday {$day}; use ".implode(', ', self::DAYS).'.');
            }

            $parsed = [];

            foreach ($dayWindows as $window) {
                [$start, $end] = [self::minutes($window[0], false), self::minutes($window[1], true)];

                if ($start >= $end) {
                    throw new InvalidCalendar("Working window {$window[0]}–{$window[1]} on {$day} must end after it starts.");
                }

                $parsed[] = [$start, $end];
            }

            sort($parsed);

            for ($i = 1, $n = count($parsed); $i < $n; $i++) {
                if ($parsed[$i][0] < $parsed[$i - 1][1]) {
                    throw new InvalidCalendar("Working windows on {$day} overlap.");
                }
            }

            if ($parsed !== []) {
                $windows[$day] = $parsed;
            }
        }

        if ($windows === []) {
            throw new InvalidCalendar('A working-hours calendar needs at least one working window.');
        }

        return $windows;
    }

    private static function minutes(string $time, bool $allowMidnightEnd): int
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $match) === 1) {
            return (int) $match[1] * 60 + (int) $match[2];
        }

        if ($allowMidnightEnd && $time === '24:00') {
            return 1440;
        }

        throw new InvalidCalendar("Invalid time {$time}; use HH:MM.");
    }

    /**
     * @param  list<string>  $holidays
     * @return array<string, true>
     */
    private static function normaliseHolidays(array $holidays): array
    {
        $set = [];

        foreach ($holidays as $date) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

            if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                throw new InvalidCalendar("Invalid holiday date {$date}; use Y-m-d.");
            }

            $set[$date] = true;
        }

        return $set;
    }
}
