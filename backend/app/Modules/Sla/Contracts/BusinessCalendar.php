<?php

declare(strict_types=1);

namespace App\Modules\Sla\Contracts;

use Carbon\CarbonImmutable;

/**
 * Time arithmetic that only counts open hours (ADR-0020, docs/05-algorithms/sla-evaluation.md §Calendar).
 * Results keep the time zone of the input instant.
 */
interface BusinessCalendar
{
    /**
     * The instant when `$seconds` of working time have passed after `$start`.
     */
    public function add(CarbonImmutable $start, int $seconds): CarbonImmutable;

    /**
     * Working seconds between `$from` and `$to` (0 when `$to` is not after `$from`).
     */
    public function elapsed(CarbonImmutable $from, CarbonImmutable $to): int;
}
