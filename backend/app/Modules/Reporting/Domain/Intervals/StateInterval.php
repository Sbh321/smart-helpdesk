<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Intervals;

use Carbon\CarbonImmutable;

/**
 * A maximal period of constant tracked state (one `report_ticket_intervals` row).
 * An open interval has `endsAt = null`; its durations are measured up to the builder's "now".
 */
final readonly class StateInterval
{
    /**
     * @param  array<string, mixed>  $state  the tracked attributes during the interval
     */
    public function __construct(
        public int $sequence,
        public array $state,
        public CarbonImmutable $startsAt,
        public ?CarbonImmutable $endsAt,
        public int $seconds,
        public int $businessSeconds,
    ) {}

    public function isOpen(): bool
    {
        return $this->endsAt === null;
    }

    /**
     * Whether the interval covers the instant: `startsAt <= t < coalesce(endsAt, infinity)`.
     */
    public function covers(CarbonImmutable $instant): bool
    {
        return $this->startsAt <= $instant && ($this->endsAt === null || $instant < $this->endsAt);
    }

    public function get(string $attribute): mixed
    {
        return $this->state[$attribute] ?? null;
    }
}
