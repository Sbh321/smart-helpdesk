<?php

declare(strict_types=1);

namespace App\Modules\Sla\Domain\Timer;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The stored state of one SLA timer (`ticket_sla_timers`, docs/04-domain/sla.md). Immutable: strategies return copies.
 */
final readonly class TimerData
{
    public function __construct(
        public TimerKind $kind,
        public TimerState $state,
        public int $targetSeconds,
        public CarbonImmutable $startedAt,
        public CarbonImmutable $warningAt,
        public CarbonImmutable $dueAt,
        public ?CarbonImmutable $pausedAt = null,
        public int $pausedTotalSeconds = 0,
        public ?CarbonImmutable $warnedAt = null,
        public ?CarbonImmutable $breachedAt = null,
        public ?CarbonImmutable $metAt = null,
        public ?CarbonImmutable $cancelledAt = null,
    ) {}

    /**
     * A copy with some fields replaced (named arguments; pass null explicitly to clear a nullable field).
     *
     * @param  array<string, mixed>  $changes
     */
    public function with(array $changes): self
    {
        $values = get_object_vars($this);

        foreach ($changes as $name => $value) {
            if (! array_key_exists($name, $values)) {
                throw new InvalidArgumentException("TimerData has no field {$name}.");
            }

            $values[$name] = $value;
        }

        return new self(...$values);
    }

    /**
     * @return array{kind: string, state: string, target_seconds: int, started_at: string, warning_at: string, due_at: string, paused_at: string|null, paused_total_seconds: int, warned_at: string|null, breached_at: string|null, met_at: string|null, cancelled_at: string|null}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'state' => $this->state->value,
            'target_seconds' => $this->targetSeconds,
            'started_at' => self::format($this->startedAt),
            'warning_at' => self::format($this->warningAt),
            'due_at' => self::format($this->dueAt),
            'paused_at' => self::format($this->pausedAt),
            'paused_total_seconds' => $this->pausedTotalSeconds,
            'warned_at' => self::format($this->warnedAt),
            'breached_at' => self::format($this->breachedAt),
            'met_at' => self::format($this->metAt),
            'cancelled_at' => self::format($this->cancelledAt),
        ];
    }

    /**
     * @param  array{kind: string, state: string, target_seconds: int, started_at: string, warning_at: string, due_at: string, paused_at?: string|null, paused_total_seconds?: int, warned_at?: string|null, breached_at?: string|null, met_at?: string|null, cancelled_at?: string|null}  $row
     */
    public static function fromArray(array $row): self
    {
        $date = static fn (?string $value): ?CarbonImmutable => $value === null ? null : CarbonImmutable::parse($value)->utc();

        return new self(
            kind: TimerKind::from($row['kind']),
            state: TimerState::from($row['state']),
            targetSeconds: $row['target_seconds'],
            startedAt: CarbonImmutable::parse($row['started_at'])->utc(),
            warningAt: CarbonImmutable::parse($row['warning_at'])->utc(),
            dueAt: CarbonImmutable::parse($row['due_at'])->utc(),
            pausedAt: $date($row['paused_at'] ?? null),
            pausedTotalSeconds: $row['paused_total_seconds'] ?? 0,
            warnedAt: $date($row['warned_at'] ?? null),
            breachedAt: $date($row['breached_at'] ?? null),
            metAt: $date($row['met_at'] ?? null),
            cancelledAt: $date($row['cancelled_at'] ?? null),
        );
    }

    /**
     * ISO 8601 in UTC, the storage and explanation format.
     *
     * @return ($instant is null ? null : string)
     */
    public static function format(?CarbonImmutable $instant): ?string
    {
        return $instant?->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
