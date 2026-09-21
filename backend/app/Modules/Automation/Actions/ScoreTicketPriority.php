<?php

declare(strict_types=1);

namespace App\Modules\Automation\Actions;

use App\Modules\Automation\Contracts\PriorityStrategy;
use App\Modules\Automation\Domain\Priority\CustomerTier;
use App\Modules\Automation\Domain\Priority\PriorityInput;
use App\Modules\Automation\Domain\Priority\PriorityLevel;
use App\Modules\Sla\Actions\RecomputeTicketSla;
use App\Modules\Sla\Contracts\BusinessCalendar as BusinessCalendarContract;
use App\Modules\Sla\Models\BusinessCalendar;
use App\Modules\Sla\Support\SlaCalendarResolver;
use App\Modules\Tenancy\Settings\Settings;
use App\Modules\Tickets\Domain\Priority;
use App\Modules\Tickets\Events\PriorityChanged;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketEvent;
use App\Support\Time\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Scores one ticket through the `PriorityStrategy` contract (docs/05-algorithms/priority-scoring.md)
 * and stores score, level and explanation. A manual override keeps winning: the score is still
 * refreshed, the effective level does not move. When the effective level changes, SLA deadlines
 * are recomputed and, unless `$recordHistory` is false (the first scoring of a new ticket), a
 * `priority_changed` history row and the `PriorityChanged` event are written.
 */
final readonly class ScoreTicketPriority
{
    public function __construct(
        private PriorityStrategy $strategy,
        private SlaCalendarResolver $calendars,
        private RecomputeTicketSla $sla,
        private Clock $clock,
        private Settings $settings,
    ) {}

    /**
     * @param  BusinessCalendarContract|null  $ageCalendar  pass `ageCalendar()` when scoring many tickets of one workspace
     */
    public function __invoke(
        Ticket $ticket,
        ?string $actorId = null,
        bool $recordHistory = true,
        ?BusinessCalendarContract $ageCalendar = null,
    ): Ticket {
        $ageCalendar ??= $this->ageCalendar();

        return DB::transaction(function () use ($ticket, $actorId, $recordHistory, $ageCalendar): Ticket {
            $locked = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $locked->loadMissing('contact.organization');
            $now = $this->clock->now();
            $hours = $ageCalendar->elapsed($locked->created_at, $now) / 3600;
            $result = $this->strategy->score(new PriorityInput(
                impact: $locked->impact,
                urgency: $locked->urgency,
                tier: CustomerTier::from($locked->contact->tier()->value),
                hoursWaited: max(0.0, $hours),
            ));
            $previous = $locked->effectivePriority();
            $override = $locked->priority_override_level;
            $locked->forceFill([
                'priority_score' => $result->score,
                'priority_level' => Priority::from($result->level->value),
                'priority_explanation' => $result->explanation($override === null ? null : PriorityLevel::from($override->value)),
                'priority_settings_version' => $this->settings->version(),
            ]);
            $levelChanged = $previous !== $locked->effectivePriority();
            if ($levelChanged) {
                $locked->updated_at = $now;
            }
            // A refreshed score is not an edit: `updated_at` moves only with the effective level,
            // and then to the Clock's now, never to Eloquent's wall-clock stamp.
            $locked->timestamps = false;
            $locked->save();
            $locked->timestamps = true;

            if ($levelChanged) {
                ($this->sla)($locked);
                if ($recordHistory) {
                    TicketEvent::query()->create([
                        'ticket_id' => $locked->id,
                        'type' => 'priority_changed',
                        'actor_type' => $actorId === null ? 'system' : 'user',
                        'actor_id' => $actorId,
                        'old_values' => ['priority' => $previous->value],
                        'new_values' => ['priority' => $locked->effectivePriority()->value, 'score' => $result->score],
                        'note' => null,
                        'created_at' => $now,
                    ]);
                    event(new PriorityChanged($locked->tenant_id, $locked->id, $previous->value, $locked->effectivePriority()->value));
                }
            }

            return $locked;
        });
    }

    /**
     * The calendar on which a ticket's waiting time is measured: the workspace's default business
     * calendar, or 24×7 when there is none.
     */
    public function ageCalendar(): BusinessCalendarContract
    {
        return $this->calendars->forId(BusinessCalendar::query()->where('is_default', true)->value('id'));
    }
}
