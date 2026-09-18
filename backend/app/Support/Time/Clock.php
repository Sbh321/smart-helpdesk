<?php

declare(strict_types=1);

namespace App\Support\Time;

use Carbon\CarbonImmutable;

/**
 * Source of "now" for all time-based logic (SLA timers, ageing, schedules).
 * Tests and experiments bind {@see FrozenClock}.
 */
interface Clock
{
    public function now(): CarbonImmutable;
}
