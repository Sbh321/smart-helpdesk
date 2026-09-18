<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Intervals;

use Carbon\CarbonImmutable;

/**
 * Derived measures over one entity's intervals (docs/05-algorithms/history-and-time-analytics.md §4).
 * Database-wide versions (backlog across tickets) are SQL over `report_ticket_intervals`; these are the reference.
 */
final class IntervalMeasures
{
    /**
     * Time spent per value of an attribute (e.g. time in status).
     *
     * @param  list<StateInterval>  $intervals
     * @return array<string, array{seconds: int, business_seconds: int}> keyed by the value ('' for null)
     */
    public static function timeBy(array $intervals, string $attribute): array
    {
        $totals = [];

        foreach ($intervals as $interval) {
            $key = (string) $interval->get($attribute);
            $totals[$key] ??= ['seconds' => 0, 'business_seconds' => 0];
            $totals[$key]['seconds'] += $interval->seconds;
            $totals[$key]['business_seconds'] += $interval->businessSeconds;
        }

        return $totals;
    }

    /**
     * The interval covering the instant, or null before the first interval.
     *
     * @param  list<StateInterval>  $intervals
     */
    public static function at(array $intervals, CarbonImmutable $instant): ?StateInterval
    {
        foreach ($intervals as $interval) {
            if ($interval->covers($instant)) {
                return $interval;
            }
        }

        return null;
    }

    /**
     * Number of boundaries where the attribute moved from one non-null value to a different non-null value
     * (for `assignee_id`: reassignments; assigning from or unassigning to nobody does not count).
     *
     * @param  list<StateInterval>  $intervals
     */
    public static function switches(array $intervals, string $attribute): int
    {
        $count = 0;

        for ($i = 1, $n = count($intervals); $i < $n; $i++) {
            [$before, $after] = [$intervals[$i - 1]->get($attribute), $intervals[$i]->get($attribute)];

            if ($before !== null && $after !== null && $before !== $after) {
                $count++;
            }
        }

        return $count;
    }
}
