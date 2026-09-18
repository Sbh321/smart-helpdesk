<?php

declare(strict_types=1);

namespace App\Modules\Sla\Domain\Timer;

/**
 * Result of an SLA strategy operation: the timer to store and the events to record.
 */
final readonly class TimerOutcome
{
    /**
     * @param  list<SlaEvent>  $events
     */
    public function __construct(
        public TimerData $timer,
        public array $events,
        public string $strategy,
        public string $strategyVersion,
    ) {}

    public function has(SlaEventType $type): bool
    {
        foreach ($this->events as $event) {
            if ($event->type === $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{strategy: string, strategy_version: string, timer: array<string, mixed>, events: list<array{type: string, kind: string, at: string, details: array<string, scalar|null>}>}
     */
    public function explanation(): array
    {
        return [
            'strategy' => $this->strategy,
            'strategy_version' => $this->strategyVersion,
            'timer' => $this->timer->toArray(),
            'events' => array_map(static fn (SlaEvent $event): array => $event->toArray(), $this->events),
        ];
    }
}
