<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Intervals;

use Carbon\CarbonImmutable;

/**
 * A domain event reduced to the attribute values it sets (status changed, assigned, priority changed, …).
 * Events are swept in `(occurredAt, id)` order; `id` is the event's UUID v7, so ties keep insertion order.
 */
final readonly class StateEvent
{
    /**
     * @param  array<string, mixed>  $sets  attribute => new value; attributes the builder does not track are ignored
     */
    public function __construct(
        public string $id,
        public CarbonImmutable $occurredAt,
        public array $sets,
    ) {}
}
