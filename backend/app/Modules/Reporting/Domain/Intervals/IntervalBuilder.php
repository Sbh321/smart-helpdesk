<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Intervals;

use App\Modules\Reporting\Domain\Exceptions\InvalidHistory;
use App\Modules\Sla\Contracts\BusinessCalendar;
use App\Support\Time\Clock;
use Carbon\CarbonImmutable;

/**
 * Sweeps ordered state events into maximal intervals of constant tracked state
 * (docs/05-algorithms/history-and-time-analytics.md §4).
 *
 * The tracked attributes are the keys of the initial state (for tickets: {@see self::TICKET_ATTRIBUTES}).
 * An event that changes nothing tracked does not split an interval; two changes at the same instant
 * give a zero-length interval, which is kept so that every transition is visible.
 * The last interval stays open; its durations run to `$now` (the {@see Clock} when not given).
 */
final readonly class IntervalBuilder
{
    public const array TICKET_ATTRIBUTES = ['status', 'assignee_id', 'team_id', 'priority'];

    public function __construct(private Clock $clock) {}

    /**
     * @param  array<string, mixed>  $initial  tracked attributes from the "created" event
     * @param  list<StateEvent>  $events  later events in any order
     * @return list<StateInterval>
     */
    public function build(
        array $initial,
        CarbonImmutable $startsAt,
        array $events,
        BusinessCalendar $calendar,
        ?CarbonImmutable $now = null,
    ): array {
        if ($initial === []) {
            throw new InvalidHistory('The initial state needs at least one tracked attribute.');
        }

        $now ??= $this->clock->now();
        $state = $initial;
        $start = $startsAt;
        $result = [];

        foreach (self::ordered($events) as $event) {
            if ($event->occurredAt < $startsAt) {
                throw new InvalidHistory("Event {$event->id} happened before the entity was created.");
            }

            $next = array_replace($state, array_intersect_key($event->sets, $state));

            if ($next === $state) {
                continue;
            }

            $result[] = self::interval(count($result), $state, $start, $event->occurredAt, $event->occurredAt, $calendar);
            [$state, $start] = [$next, $event->occurredAt];
        }

        $result[] = self::interval(count($result), $state, $start, null, $now, $calendar);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private static function interval(
        int $sequence,
        array $state,
        CarbonImmutable $start,
        ?CarbonImmutable $end,
        CarbonImmutable $measuredTo,
        BusinessCalendar $calendar,
    ): StateInterval {
        return new StateInterval(
            $sequence,
            $state,
            $start,
            $end,
            max(0, $measuredTo->getTimestamp() - $start->getTimestamp()),
            $calendar->elapsed($start, $measuredTo),
        );
    }

    /**
     * @param  list<StateEvent>  $events
     * @return list<StateEvent>
     */
    private static function ordered(array $events): array
    {
        usort($events, static fn (StateEvent $a, StateEvent $b): int => [$a->occurredAt, $a->id] <=> [$b->occurredAt, $b->id]);

        return $events;
    }
}
