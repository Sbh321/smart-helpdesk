<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\History;

use App\Modules\Reporting\Domain\Exceptions\InvalidHistory;
use Carbon\CarbonImmutable;

/**
 * Point-in-time reconstruction of one entity from its `entity_changes` rows
 * (docs/05-algorithms/history-and-time-analytics.md §3).
 *
 * A state is an attribute map; `null` means the entity did not exist at that instant.
 * A change belongs to the state at `t` when `occurredAt <= t`. Changes are ordered by version;
 * a history whose times go backwards as versions go up is rejected, so both replays cut at the same place.
 * Attributes the trigger never records (excluded columns, `updated_at`) keep their current value
 * in backward replay and are absent from forward replay.
 */
final class ChangeReplayer
{
    /**
     * Backward replay: undo, newest first, every change that happened after `$at`.
     *
     * @param  array<string, mixed>|null  $current  the current row, or null when the entity has been deleted
     * @param  list<EntityChange>  $changes  the entity's changes in any order (only those after `$at` are needed)
     * @return array<string, mixed>|null
     */
    public function asOf(?array $current, array $changes, CarbonImmutable $at): ?array
    {
        $state = $current ?? [];

        foreach (array_reverse(self::ordered($changes)) as $change) {
            if ($change->occurredAt <= $at) {
                break;
            }

            if ($change->operation === ChangeOperation::Insert) {
                return null;
            }

            foreach ($change->changes as $attribute => $pair) {
                $state[$attribute] = $pair['old'];
            }
        }

        return $current === null && $state === [] ? null : $state;
    }

    /**
     * Forward replay: apply the `new` values from the insert (or from `$initial`) up to and including `$at`.
     *
     * @param  list<EntityChange>  $changes  the entity's changes in any order
     * @param  array<string, mixed>|null  $initial  the state before the first given change (null: not yet created)
     * @return array<string, mixed>|null
     */
    public function forwardTo(array $changes, CarbonImmutable $at, ?array $initial = null): ?array
    {
        $state = $initial;

        foreach (self::ordered($changes) as $change) {
            if ($change->occurredAt > $at) {
                break;
            }

            $state = match ($change->operation) {
                ChangeOperation::Insert => $state === null
                    ? array_map(static fn (array $pair): mixed => $pair['new'], $change->changes)
                    : throw new InvalidHistory("Version {$change->version} inserts an entity that already exists."),
                ChangeOperation::Update => self::applyUpdate($state, $change),
                ChangeOperation::Delete => $state !== null
                    ? null
                    : throw new InvalidHistory("Version {$change->version} deletes an entity that does not exist."),
            };
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>|null  $state
     * @return array<string, mixed>
     */
    private static function applyUpdate(?array $state, EntityChange $change): array
    {
        if ($state === null) {
            throw new InvalidHistory("Version {$change->version} updates an entity that does not exist.");
        }

        foreach ($change->changes as $attribute => $pair) {
            $state[$attribute] = $pair['new'];
        }

        return $state;
    }

    /**
     * @param  list<EntityChange>  $changes
     * @return list<EntityChange>
     */
    private static function ordered(array $changes): array
    {
        usort($changes, static fn (EntityChange $a, EntityChange $b): int => $a->version <=> $b->version);

        for ($i = 1, $n = count($changes); $i < $n; $i++) {
            [$previous, $change] = [$changes[$i - 1], $changes[$i]];

            if ($previous->version === $change->version) {
                throw new InvalidHistory("Version {$change->version} appears twice.");
            }

            if ($change->occurredAt < $previous->occurredAt) {
                throw new InvalidHistory("Version {$change->version} is older than version {$previous->version}.");
            }
        }

        return $changes;
    }
}
