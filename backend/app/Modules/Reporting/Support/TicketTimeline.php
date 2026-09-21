<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Support;

use App\Modules\Reporting\Domain\Intervals\StateEvent;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketEvent;
use Carbon\CarbonImmutable;

/**
 * A ticket's domain history (`ticket_events`) reduced to the four tracked attributes of the interval
 * sweep (docs/05-algorithms/history-and-time-analytics.md §4). `ticket_events` is used, not
 * `entity_changes`, because its times come from the application `Clock`, like the ticket's own
 * timestamps, the SLA timers and the demo clock.
 */
final readonly class TicketTimeline
{
    /** Event types that set a tracked attribute. Other types (comments, edits, SLA) are ignored. */
    private const array STATE_TYPES = [
        'created', 'status_changed', 'reopened', 'assigned', 'unassigned',
        'priority_changed', 'priority_overridden', 'duplicate_marked',
    ];

    /** Event value key => tracked attribute. */
    private const array KEYS = ['status' => 'status', 'agent_id' => 'assignee_id', 'team_id' => 'team_id', 'priority' => 'priority'];

    /**
     * @param  array{status: string, assignee_id: string|null, team_id: string|null, priority: string}  $initial
     * @param  list<StateEvent>  $events
     */
    public function __construct(public array $initial, public CarbonImmutable $createdAt, public array $events) {}

    public static function for(Ticket $ticket): self
    {
        /** @var list<TicketEvent> $rows */
        $rows = TicketEvent::query()->where('ticket_id', $ticket->id)->whereIn('type', self::STATE_TYPES)
            ->orderBy('created_at')->orderBy('id')->get()->all();

        // The value before the first change of an attribute is that change's old value; an attribute
        // no event ever changed has had its current value all along. So a ticket without a "created"
        // event (seeded data) still gets the right starting state.
        $initial = [
            'status' => $ticket->status->value,
            'assignee_id' => $ticket->assigned_agent_id,
            'team_id' => $ticket->team_id,
            'priority' => $ticket->effectivePriority()->value,
        ];
        $seen = [];
        $events = [];
        foreach ($rows as $row) {
            $old = (array) ($row->old_values ?? []);
            $new = (array) ($row->new_values ?? []);
            $sets = [];
            foreach (self::KEYS as $key => $attribute) {
                if (! array_key_exists($key, $new)) {
                    continue;
                }
                if ($row->type === 'created') {
                    $initial[$attribute] = $new[$key];
                    $seen[$attribute] = true;

                    continue;
                }
                if (! isset($seen[$attribute]) && array_key_exists($key, $old)) {
                    $initial[$attribute] = $old[$key];
                }
                $seen[$attribute] = true;
                $sets[$attribute] = $new[$key];
            }
            if ($sets !== []) {
                // An event cannot precede the ticket. When one appears to (a shifted demo clock, an
                // import), it is taken to have happened at creation instead of failing the rebuild.
                $at = $row->created_at->lessThan($ticket->created_at) ? $ticket->created_at : $row->created_at;
                $events[] = new StateEvent($row->id, $at, $sets);
            }
        }

        /** @var array{status: string, assignee_id: string|null, team_id: string|null, priority: string} $initial */
        return new self($initial, $ticket->created_at, $events);
    }
}
