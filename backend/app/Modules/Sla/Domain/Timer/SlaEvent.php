<?php

declare(strict_types=1);

namespace App\Modules\Sla\Domain\Timer;

use Carbon\CarbonImmutable;

/**
 * Something that happened to a timer; stored as an SLA event and ticket history, and turned into
 * notifications for warning and breach.
 */
final readonly class SlaEvent
{
    /**
     * @param  array<string, scalar|null>  $details
     */
    public function __construct(
        public SlaEventType $type,
        public TimerKind $kind,
        public CarbonImmutable $at,
        public array $details = [],
    ) {}

    /**
     * @return array{type: string, kind: string, at: string, details: array<string, scalar|null>}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'kind' => $this->kind->value,
            'at' => TimerData::format($this->at),
            'details' => $this->details,
        ];
    }
}
