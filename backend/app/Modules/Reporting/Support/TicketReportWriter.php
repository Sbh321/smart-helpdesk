<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Support;

use App\Modules\Reporting\Domain\Intervals\IntervalBuilder;
use App\Modules\Reporting\Domain\Intervals\IntervalMeasures;
use App\Modules\Reporting\Domain\Intervals\StateInterval;
use App\Modules\Reporting\Models\ReportTicketFact;
use App\Modules\Reporting\Models\ReportTicketInterval;
use App\Modules\Sla\Contracts\BusinessCalendar;
use App\Modules\Sla\Models\TicketSlaTimer;
use App\Modules\Sla\Support\SlaCalendarResolver;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketAssignment;
use App\Modules\Tickets\Models\TicketComment;
use App\Support\Time\Clock;
use BackedEnum;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Computes and stores the intervals and facts of one ticket (docs/04-domain/reporting.md §Operations).
 * Everything is recomputed from the ticket's history on each call, so the result does not depend on
 * how many times or in which order it ran: an incremental refresh and a full rebuild agree.
 *
 * Durations of the still-open interval are not stored (they grow with the clock): the interval keeps
 * null durations, and the fact sums (`pending_s`, `unassigned_s`) count closed intervals only.
 */
final readonly class TicketReportWriter
{
    private const array DONE = ['resolved', 'closed'];

    public function __construct(
        private IntervalBuilder $builder,
        private SlaCalendarResolver $calendars,
        private Clock $clock,
    ) {}

    /** Recomputes and stores the ticket's rows; removes them when the ticket no longer exists. */
    public function refresh(string $ticketId): void
    {
        DB::transaction(function () use ($ticketId): void {
            $ticket = Ticket::query()->find($ticketId);
            if ($ticket === null) {
                ReportTicketInterval::query()->where('ticket_id', $ticketId)->delete();
                ReportTicketFact::query()->where('ticket_id', $ticketId)->delete();

                return;
            }

            $report = $this->compute($ticket);
            ReportTicketInterval::query()->where('ticket_id', $ticket->id)->delete();
            foreach ($report->intervals as $interval) {
                (new ReportTicketInterval)->forceFill([...$interval, 'id' => (string) Str::uuid7()])->save();
            }

            $fact = ReportTicketFact::query()->firstOrNew(['ticket_id' => $ticket->id]);
            $fact->forceFill([...$report->facts, 'refreshed_at' => $this->clock->now()]);
            if (! $fact->exists) {
                $fact->id = (string) Str::uuid7();
            }
            $fact->save();
        });
    }

    public function compute(Ticket $ticket): TicketReport
    {
        $timeline = TicketTimeline::for($ticket);
        $calendar = $this->calendarFor($ticket);
        $intervals = $this->builder->build($timeline->initial, $timeline->createdAt, $timeline->events, $calendar);

        return new TicketReport(
            array_map(fn (StateInterval $interval): array => [
                'ticket_id' => $ticket->id,
                'seq' => $interval->sequence,
                'status' => (string) $interval->get('status'),
                'assigned_agent_id' => $interval->get('assignee_id'),
                'team_id' => $interval->get('team_id'),
                'priority_level' => (string) $interval->get('priority'),
                'starts_at' => $interval->startsAt,
                'ends_at' => $interval->endsAt,
                'wall_seconds' => $interval->isOpen() ? null : $interval->seconds,
                'business_seconds' => $interval->isOpen() ? null : $interval->businessSeconds,
            ], $intervals),
            $this->facts($ticket, $timeline, $intervals, $calendar),
        );
    }

    /**
     * @param  list<StateInterval>  $intervals
     * @return array<string, mixed>
     */
    private function facts(Ticket $ticket, TicketTimeline $timeline, array $intervals, BusinessCalendar $calendar): array
    {
        $created = $ticket->created_at;
        $closed = array_values(array_filter($intervals, fn (StateInterval $interval): bool => ! $interval->isOpen()));
        $comments = TicketComment::query()->where('ticket_id', $ticket->id)
            ->selectRaw('count(*) as total')
            ->selectRaw("count(*) filter (where visibility = 'public' and author_type = 'user') as public_replies")
            ->selectRaw("count(*) filter (where author_type = 'contact') as requester_replies")
            ->first();
        $assignment = TicketAssignment::query()->where('ticket_id', $ticket->id)
            ->orderByDesc('created_at')->orderByDesc('id')->first();
        $priorityExplanation = (array) ($ticket->priority_explanation ?? []);
        $assignmentExplanation = (array) ($assignment->explanation ?? []);

        return [
            'ticket_id' => $ticket->id,
            'created_at' => $created,
            'first_responded_at' => $ticket->first_responded_at,
            'resolved_at' => $ticket->resolved_at,
            'closed_at' => $ticket->closed_at,
            'channel' => $ticket->created_via,
            'category_id' => $ticket->category_id,
            'team_id' => $ticket->team_id,
            'assigned_agent_id' => $ticket->assigned_agent_id,
            'organization_id' => $ticket->organization_id,
            'contact_id' => $ticket->contact_id,
            'priority_level' => $ticket->effectivePriority()->value,
            'initial_priority_level' => (string) $timeline->initial['priority'],
            'first_response_wall_s' => $this->seconds($created, $ticket->first_responded_at),
            'first_response_business_s' => $ticket->first_responded_at === null ? null : $calendar->elapsed($created, $ticket->first_responded_at),
            'resolution_wall_s' => $this->seconds($created, $ticket->resolved_at),
            'resolution_business_s' => $ticket->resolved_at === null ? null : $calendar->elapsed($created, $ticket->resolved_at),
            'pending_s' => IntervalMeasures::timeBy($closed, 'status')['pending']['seconds'] ?? 0,
            'unassigned_s' => array_sum(array_map(
                fn (StateInterval $interval): int => $interval->seconds,
                array_filter($closed, fn (StateInterval $interval): bool => $interval->get('assignee_id') === null
                    && ! in_array($interval->get('status'), self::DONE, true)),
            )),
            'reopen_count' => (int) $ticket->reopen_count,
            'reassign_count' => IntervalMeasures::switches($intervals, 'assignee_id'),
            'comment_count' => (int) ($comments->total ?? 0),
            'public_reply_count' => (int) ($comments->public_replies ?? 0),
            'email_in_count' => (int) ($comments->requester_replies ?? 0),
            'first_response_sla' => $this->slaOutcome($ticket->id, 'first_response'),
            'resolution_sla' => $this->slaOutcome($ticket->id, 'resolution'),
            'priority_overridden' => $ticket->priority_override_level !== null,
            'closed_as_duplicate' => $ticket->duplicate_of_id !== null,
            'priority_strategy' => $this->strategy($priorityExplanation),
            'assignment_strategy' => $this->strategy($assignmentExplanation),
        ];
    }

    /** Business time follows the calendar of the ticket's SLA timers; without one it is wall-clock. */
    private function calendarFor(Ticket $ticket): BusinessCalendar
    {
        $calendarId = TicketSlaTimer::query()->where('ticket_id', $ticket->id)
            ->orderByDesc('cycle')->orderByRaw("kind = 'resolution' desc")->value('calendar_id');

        return $this->calendars->forId(is_string($calendarId) ? $calendarId : null);
    }

    /** The latest cycle's outcome of one timer kind: `met`, `breached`, `cancelled` or `running`. */
    private function slaOutcome(string $ticketId, string $kind): ?string
    {
        $state = TicketSlaTimer::query()->where('ticket_id', $ticketId)->where('kind', $kind)
            ->orderByDesc('cycle')->value('state');
        $value = $state instanceof BackedEnum ? (string) $state->value : (is_string($state) ? $state : null);

        return match ($value) {
            null => null,
            'met', 'breached', 'cancelled' => $value,
            default => 'running', // running, warning and paused are all still counting
        };
    }

    /** @param array<string, mixed> $explanation */
    private function strategy(array $explanation): ?string
    {
        $name = $explanation['strategy'] ?? null;

        return is_string($name) ? Str::limit($name.'@'.($explanation['strategy_version'] ?? '?'), 64, '') : null;
    }

    private function seconds(CarbonImmutable $from, ?CarbonImmutable $to): ?int
    {
        return $to === null ? null : max(0, $to->getTimestamp() - $from->getTimestamp());
    }
}
