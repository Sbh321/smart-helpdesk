<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\History;

use App\Modules\Reporting\Domain\Exceptions\InvalidHistory;
use Carbon\CarbonImmutable;

/**
 * One `entity_changes` row (docs/05-algorithms/history-and-time-analytics.md §2).
 * `version` is consecutive per entity and is the replay order; `occurredAt` decides which side of an instant the change is on.
 */
final readonly class EntityChange
{
    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $changes  attribute => old and new value (old is null on insert, new is null on delete)
     */
    public function __construct(
        public int $version,
        public ChangeOperation $operation,
        public CarbonImmutable $occurredAt,
        public array $changes,
    ) {
        if ($version < 1) {
            throw new InvalidHistory("A change version starts at 1, got {$version}.");
        }
    }

    /**
     * Builds a change from the shape stored in the database (`operation` as text, `changes` as decoded jsonb).
     *
     * @param  array<string, array{old?: mixed, new?: mixed}>  $changes
     */
    public static function fromStored(int $version, string $operation, CarbonImmutable $occurredAt, array $changes): self
    {
        $normalised = [];

        foreach ($changes as $attribute => $pair) {
            $normalised[$attribute] = ['old' => $pair['old'] ?? null, 'new' => $pair['new'] ?? null];
        }

        $parsed = ChangeOperation::tryFrom($operation)
            ?? throw new InvalidHistory("Unknown change operation {$operation}.");

        return new self($version, $parsed, $occurredAt, $normalised);
    }
}
