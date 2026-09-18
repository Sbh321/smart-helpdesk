<?php

declare(strict_types=1);

namespace App\Support\Time;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * A clock that only moves when told to. Used by tests, experiments and the demo `demo:tick` command.
 */
final class FrozenClock implements Clock
{
    private CarbonImmutable $now;

    public function __construct(DateTimeInterface|string $now = '2026-09-01 09:00:00')
    {
        $this->now = CarbonImmutable::parse($now, 'UTC')->utc();
    }

    public function now(): CarbonImmutable
    {
        return $this->now;
    }

    public function set(DateTimeInterface|string $now): void
    {
        $this->now = CarbonImmutable::parse($now, 'UTC')->utc();
    }

    public function advance(string $interval): void
    {
        $this->now = $this->now->add($interval);
    }
}
