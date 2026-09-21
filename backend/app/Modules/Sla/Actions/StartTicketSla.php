<?php

declare(strict_types=1);

namespace App\Modules\Sla\Actions;

use App\Modules\Sla\Domain\Timer\TimerKind;
use App\Modules\Sla\Exceptions\SlaTargetMissing;
use App\Modules\Sla\Models\SlaPolicy;
use App\Modules\Sla\Models\TicketSlaTimer;
use App\Modules\Sla\Support\SlaStrategyFactory;
use App\Modules\Sla\Support\SlaTimerStore;
use App\Modules\Tenancy\Settings\Settings;
use App\Modules\Tickets\Models\Ticket;

final readonly class StartTicketSla
{
    public function __construct(
        private SlaStrategyFactory $strategies,
        private SlaTimerStore $store,
        private EnsureDefaultSlaPolicy $ensureDefaultPolicy,
        private Settings $settings,
    ) {}

    /**
     * @throws SlaTargetMissing
     */
    public function __invoke(Ticket $ticket): void
    {
        $tier = $ticket->contact->tier()->value;
        $policy = SlaPolicy::query()->where('applies_to_tier', $tier)->first()
            ?? SlaPolicy::query()->where('is_default', true)->first()
            ?? ($this->ensureDefaultPolicy)();
        $target = $policy->targetFor($ticket->effectivePriority());
        $strategy = $this->strategies->forWarningFraction((float) $policy->warning_fraction);

        // A ticket an agent enters for the contact (created in the UI) gets no first-response timer
        // when the workspace switched that off (docs/04-domain/sla.md §Rules).
        $skipFirstResponse = $ticket->created_via === 'ui'
            && ! $this->settings->get('sla.first_response_applies_to_agent_created', true);

        foreach ([
            [TimerKind::FirstResponse, $target->first_response_minutes],
            [TimerKind::Resolution, $target->resolution_minutes],
        ] as [$kind, $minutes]) {
            if ($kind === TimerKind::FirstResponse && $skipFirstResponse) {
                continue;
            }
            if (TicketSlaTimer::query()->where('ticket_id', $ticket->id)->where('kind', $kind->value)->where('cycle', 1)->exists()) {
                continue;
            }

            $this->store->start($ticket, $policy, $kind, $minutes, 1, $strategy);
        }
    }
}
