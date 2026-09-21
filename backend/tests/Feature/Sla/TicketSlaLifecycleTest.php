<?php

declare(strict_types=1);

use App\Modules\Contacts\Enums\OrganizationTier;
use App\Modules\Contacts\Models\Organization;
use App\Modules\Platform\Actions\ProvisionTenant;
use App\Modules\Sla\Domain\Timer\TimerKind;
use App\Modules\Sla\Domain\Timer\TimerState;
use App\Modules\Sla\Models\BusinessCalendar;
use App\Modules\Sla\Models\SlaPolicy;
use App\Modules\Sla\Models\TicketSlaTimer;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Models\Ticket;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Illuminate\Support\Collection;

require_once __DIR__.'/../Tickets/TicketTestHelpers.php';

/*
 * The SLA timelines of docs/05-algorithms/sla-evaluation.md, end to end through the API with a frozen
 * clock. Impact 2 and urgency 2 score P3 for a standard-tier contact, which the helper asserts, so the
 * default targets in play are 240 min (first response) and 1 440 min (resolution).
 */

const WEEKDAYS_9_TO_5 = [
    'mon' => [['09:00', '17:00']], 'tue' => [['09:00', '17:00']], 'wed' => [['09:00', '17:00']],
    'thu' => [['09:00', '17:00']], 'fri' => [['09:00', '17:00']],
];

function slaFreeze(string $at): FrozenClock
{
    $clock = new FrozenClock($at);
    app()->instance(Clock::class, $clock);

    return $clock;
}

function slaTenant(string $slug): Tenant
{
    return app(ProvisionTenant::class)($slug, 'SLA '.$slug)['tenant'];
}

/** Creates a P3 ticket through the API and returns its id. */
function slaTicket(Tenant $tenant, string $title = 'Printer is unavailable', ?string $expectedPriority = 'P3'): string
{
    [$contact, $category] = ticketPrerequisites($tenant);

    $ticketId = test()->postJson('/v1/tickets', [
        'title' => $title,
        'description' => 'The shared printer on the second floor is jammed.',
        'contact_id' => $contact->id,
        'category_id' => $category->id,
        'impact' => 2,
        'urgency' => 2,
    ])->assertCreated()->json('data.id');

    if ($expectedPriority !== null) {
        $priority = Ticket::query()->withoutTenancy()->findOrFail($ticketId)->effectivePriority()->value;
        expect($priority)->toBe($expectedPriority);
    }

    return $ticketId;
}

function slaForceStatus(Tenant $tenant, string $ticketId, TicketStatus $status): void
{
    $tenant->run(fn () => Ticket::query()->findOrFail($ticketId)->forceFill(['status' => $status])->save());
}

/** @return Collection<int, TicketSlaTimer> first response first, then resolution cycles */
function slaTimers(string $ticketId): Collection
{
    return TicketSlaTimer::query()->withoutTenancy()->where('ticket_id', $ticketId)
        ->orderBy('kind')->orderBy('cycle')->get();
}

it('starts first-response and resolution timers when a ticket is created', function (): void {
    slaFreeze('2026-09-19 09:00:00');
    $tenant = slaTenant('sla-ticket');
    actingAsRole($tenant, 'agent');

    $timers = slaTimers(slaTicket($tenant));

    expect($timers)->toHaveCount(2)
        ->and($timers[0]->kind)->toBe(TimerKind::FirstResponse)
        ->and($timers[0]->state)->toBe(TimerState::Running)
        ->and($timers[0]->target_minutes)->toBe(240)
        ->and($timers[0]->warning_at->toIso8601ZuluString())->toBe('2026-09-19T12:00:00Z')
        ->and($timers[0]->due_at->toIso8601ZuluString())->toBe('2026-09-19T13:00:00Z')
        ->and($timers[1]->kind)->toBe(TimerKind::Resolution)
        ->and($timers[1]->target_minutes)->toBe(1440)
        ->and($timers[1]->due_at->toIso8601ZuluString())->toBe('2026-09-20T09:00:00Z')
        ->and($timers[1]->calendar_id)->toBeNull()
        ->and($timers[1]->strategy)->toBe('simple_sla_timer');
});

it('pauses both timers while pending and moves deadlines by the paused duration', function (): void {
    $clock = slaFreeze('2026-09-19 09:00:00');
    $tenant = slaTenant('sla-pending');
    actingAsRole($tenant, 'agent');
    $ticketId = slaTicket($tenant);
    slaForceStatus($tenant, $ticketId, TicketStatus::Assigned);

    $clock->set('2026-09-19 09:30:00');
    $this->postJson("/v1/tickets/{$ticketId}/transition", ['status' => 'pending'])->assertOk();
    $paused = slaTimers($ticketId);
    expect($paused->map(fn (TicketSlaTimer $timer): TimerState => $timer->state)->all())
        ->toBe([TimerState::Paused, TimerState::Paused])
        ->and($paused[0]->paused_from_state)->toBe(TimerState::Running)
        ->and($paused[0]->paused_at->toIso8601ZuluString())->toBe('2026-09-19T09:30:00Z');

    $clock->set('2026-09-19 10:30:00');
    $this->postJson("/v1/tickets/{$ticketId}/transition", ['status' => 'in_progress'])->assertOk();
    $timers = slaTimers($ticketId);
    expect($timers[0]->state)->toBe(TimerState::Running)
        ->and($timers[0]->paused_total_seconds)->toBe(3600)
        ->and($timers[0]->paused_at)->toBeNull()
        ->and($timers[0]->due_at->toIso8601ZuluString())->toBe('2026-09-19T14:00:00Z')
        ->and($timers[1]->paused_total_seconds)->toBe(3600)
        ->and($timers[1]->due_at->toIso8601ZuluString())->toBe('2026-09-20T10:00:00Z');
});

it('meets resolution and starts a fresh resolution cycle on reopen', function (): void {
    $clock = slaFreeze('2026-09-19 09:00:00');
    $tenant = slaTenant('sla-reopen');
    actingAsRole($tenant, 'agent');
    $ticketId = slaTicket($tenant);
    slaForceStatus($tenant, $ticketId, TicketStatus::Assigned);

    $clock->set('2026-09-19 10:00:00');
    $this->postJson("/v1/tickets/{$ticketId}/transition", [
        'status' => 'resolved',
        'comment' => 'The printer was repaired and tested.',
    ])->assertOk();
    $firstCycle = slaTimers($ticketId)->firstWhere('kind', TimerKind::Resolution);
    expect($firstCycle->state)->toBe(TimerState::Met)
        ->and($firstCycle->met_at->toIso8601ZuluString())->toBe('2026-09-19T10:00:00Z');

    $clock->set('2026-09-19 11:00:00');
    $this->postJson("/v1/tickets/{$ticketId}/transition", ['status' => 'in_progress'])->assertOk();
    $cycles = slaTimers($ticketId)->where('kind', TimerKind::Resolution)->values();
    expect($cycles)->toHaveCount(2)
        ->and($cycles[0]->state)->toBe(TimerState::Met)
        ->and($cycles[1]->cycle)->toBe(2)
        ->and($cycles[1]->state)->toBe(TimerState::Running)
        ->and($cycles[1]->started_at->toIso8601ZuluString())->toBe('2026-09-19T11:00:00Z')
        ->and($cycles[1]->due_at->toIso8601ZuluString())->toBe('2026-09-20T11:00:00Z');
});

it('computes a reopened cycle with the calendar it stores, not the previous cycle calendar', function (): void {
    $clock = slaFreeze('2026-09-18 09:00:00'); // a Friday
    $tenant = slaTenant('sla-reopen-calendar');
    actingAsRole($tenant, 'agent');
    $ticketId = slaTicket($tenant);
    slaForceStatus($tenant, $ticketId, TicketStatus::Assigned);
    $this->postJson("/v1/tickets/{$ticketId}/transition", ['status' => 'resolved', 'comment' => 'Repaired and tested.'])->assertOk();

    // The policy moves to a working-hours calendar after the first cycle was started as 24x7.
    $calendarId = $tenant->run(function () use ($tenant): string {
        $calendar = BusinessCalendar::factory()->forTenant($tenant)->create(['timezone' => 'UTC', 'weekly_hours' => WEEKDAYS_9_TO_5]);
        SlaPolicy::query()->where('is_default', true)->sole()->update(['calendar_id' => $calendar->id]);

        return $calendar->id;
    });

    $clock->set('2026-09-19 11:00:00'); // Saturday: closed
    $this->postJson("/v1/tickets/{$ticketId}/transition", ['status' => 'in_progress'])->assertOk();

    $second = slaTimers($ticketId)->where('kind', TimerKind::Resolution)->firstWhere('cycle', 2);
    expect($second->calendar_id)->toBe($calendarId)
        // 24 working hours from Monday 09:00 = three full days.
        ->and($second->due_at->toIso8601ZuluString())->toBe('2026-09-23T17:00:00Z')
        ->and($second->warning_at->toIso8601ZuluString())->toBe('2026-09-23T11:00:00Z');
});

it('selects the organisation tier policy and uses its warning fraction', function (): void {
    slaFreeze('2026-09-19 09:00:00');
    $tenant = slaTenant('sla-premium');
    [$contact, $category] = ticketPrerequisites($tenant);
    $tenant->run(function () use ($tenant, $contact): void {
        $organization = Organization::factory()->forTenant($tenant)->create(['tier' => 'premium']);
        $contact->forceFill(['organization_id' => $organization->id])->save();
        $policy = SlaPolicy::factory()->forTenant($tenant)->create(['applies_to_tier' => 'premium', 'warning_fraction' => 0.50]);
        foreach (['P1' => [5, 60], 'P2' => [10, 90], 'P3' => [15, 120], 'P4' => [20, 180]] as $level => [$response, $resolution]) {
            $policy->targets()->create([
                'priority_level' => $level, 'first_response_minutes' => $response, 'resolution_minutes' => $resolution,
            ]);
        }
    });
    actingAsRole($tenant, 'agent');

    $ticketId = $this->postJson('/v1/tickets', [
        'title' => 'Premium printer issue',
        'description' => 'The office printer has stopped.',
        'contact_id' => $contact->id,
        'category_id' => $category->id,
        'impact' => 2,
        'urgency' => 2,
    ])->assertCreated()->json('data.id');

    // The premium tier may raise the computed priority, so the expectation follows the stored level.
    $level = Ticket::query()->withoutTenancy()->findOrFail($ticketId)->effectivePriority()->value;
    $minutes = ['P1' => 5, 'P2' => 10, 'P3' => 15, 'P4' => 20][$level];
    $timer = slaTimers($ticketId)->firstWhere('kind', TimerKind::FirstResponse);

    expect(SlaPolicy::query()->withoutTenancy()->findOrFail($timer->policy_id)->applies_to_tier)->toBe(OrganizationTier::Premium)
        ->and($timer->target_minutes)->toBe($minutes)
        ->and((float) $timer->warning_fraction)->toBe(0.5)
        ->and($timer->started_at->diffInSeconds($timer->warning_at))->toEqual($minutes * 30)
        ->and($timer->started_at->diffInSeconds($timer->due_at))->toEqual($minutes * 60);
});

it('answers 422 sla_target_missing and creates nothing when the policy lacks the priority target', function (): void {
    slaFreeze('2026-09-19 09:00:00');
    $tenant = slaTenant('sla-missing');
    $tenant->run(fn () => SlaPolicy::query()->where('is_default', true)->sole()->targets()->delete());
    actingAsRole($tenant, 'agent');
    [$contact, $category] = ticketPrerequisites($tenant);

    $this->postJson('/v1/tickets', [
        'title' => 'Printer is unavailable',
        'description' => 'The shared printer is jammed.',
        'contact_id' => $contact->id,
        'category_id' => $category->id,
        'impact' => 2,
        'urgency' => 2,
    ])->assertStatus(422)
        ->assertJsonPath('code', 'sla_target_missing')
        ->assertJsonStructure(['meta' => ['policy_id', 'priority_level']]);

    expect(Ticket::query()->withoutTenancy()->where('tenant_id', $tenant->id)->count())->toBe(0)
        ->and(TicketSlaTimer::query()->withoutTenancy()->where('tenant_id', $tenant->id)->count())->toBe(0);
});

it('answers 422 sla_target_missing on reopen when the target was removed meanwhile', function (): void {
    slaFreeze('2026-09-19 09:00:00');
    $tenant = slaTenant('sla-missing-reopen');
    actingAsRole($tenant, 'agent');
    $ticketId = slaTicket($tenant);
    slaForceStatus($tenant, $ticketId, TicketStatus::Assigned);
    $this->postJson("/v1/tickets/{$ticketId}/transition", ['status' => 'resolved', 'comment' => 'Repaired and tested.'])->assertOk();
    $tenant->run(fn () => SlaPolicy::query()->where('is_default', true)->sole()->targets()->delete());

    $this->postJson("/v1/tickets/{$ticketId}/transition", ['status' => 'in_progress'])
        ->assertStatus(422)->assertJsonPath('code', 'sla_target_missing');

    expect(Ticket::query()->withoutTenancy()->findOrFail($ticketId)->status)->toBe(TicketStatus::Resolved);
});

it('counts only working hours for a policy with a business calendar built through the API', function (): void {
    $clock = slaFreeze('2026-09-18 16:00:00'); // Friday, one working hour left
    $tenant = slaTenant('sla-hours');
    actingAsRole($tenant, 'admin');

    $calendarId = $this->postJson('/v1/calendars', [
        'name' => 'Office hours', 'timezone' => 'UTC', 'weekly_hours' => WEEKDAYS_9_TO_5,
    ])->assertCreated()->json('data.id');
    $this->postJson("/v1/calendars/{$calendarId}/holidays", ['date' => '2026-09-22', 'name' => 'Founders day'])->assertOk();
    $policyId = $tenant->run(fn (): string => SlaPolicy::query()->where('is_default', true)->sole()->id);
    $this->patchJson("/v1/sla-policies/{$policyId}", ['calendar_id' => $calendarId])->assertOk();

    $ticketId = slaTicket($tenant);
    $timers = slaTimers($ticketId);
    // 240 min: 1 h Friday, weekend skipped, 3 h Monday. 1 440 min: 1 h Fri + 8 h Mon + (Tue holiday) 8 h Wed + 7 h Thu.
    expect($timers[0]->calendar_id)->toBe($calendarId)
        ->and($timers[0]->due_at->toIso8601ZuluString())->toBe('2026-09-21T12:00:00Z')
        ->and($timers[1]->due_at->toIso8601ZuluString())->toBe('2026-09-24T16:00:00Z');

    // Pending Friday 16:30 to Monday 10:00 pauses 0.5 h + 1 h of working time.
    slaForceStatus($tenant, $ticketId, TicketStatus::Assigned);
    $clock->set('2026-09-18 16:30:00');
    $this->postJson("/v1/tickets/{$ticketId}/transition", ['status' => 'pending'])->assertOk();
    $clock->set('2026-09-21 10:00:00');
    $this->postJson("/v1/tickets/{$ticketId}/transition", ['status' => 'in_progress'])->assertOk();

    $resumed = slaTimers($ticketId);
    expect($resumed[0]->paused_total_seconds)->toBe(5400)
        ->and($resumed[0]->due_at->toIso8601ZuluString())->toBe('2026-09-21T13:30:00Z');
});

it('cancels both timers when the ticket is closed as a duplicate', function (): void {
    $clock = slaFreeze('2026-09-19 09:00:00');
    $tenant = slaTenant('sla-duplicate');
    actingAsRole($tenant, 'admin');
    $originalId = slaTicket($tenant, 'Printer on floor two is jammed');
    $duplicateId = slaTicket($tenant, 'Printer on floor two is jammed again');

    $clock->set('2026-09-19 09:20:00');
    $this->postJson("/v1/tickets/{$duplicateId}/mark-duplicate", ['candidate_ticket_id' => $originalId])->assertOk();

    $timers = slaTimers($duplicateId);
    expect($timers->map(fn (TicketSlaTimer $timer): TimerState => $timer->state)->all())
        ->toBe([TimerState::Cancelled, TimerState::Cancelled])
        ->and($timers[0]->cancelled_at->toIso8601ZuluString())->toBe('2026-09-19T09:20:00Z')
        ->and(slaTimers($originalId)[0]->state)->toBe(TimerState::Running);
});

it('meets the first-response timer on the first public agent reply, once', function (): void {
    $clock = slaFreeze('2026-09-19 09:00:00');
    $tenant = slaTenant('sla-first-reply');
    actingAsRole($tenant, 'agent');
    $ticketId = slaTicket($tenant);

    $clock->set('2026-09-19 09:10:00');
    $this->postJson("/v1/tickets/{$ticketId}/comments", ['body' => 'Internal triage note.', 'visibility' => 'internal'])->assertCreated();
    expect(slaTimers($ticketId)[0]->state)->toBe(TimerState::Running);

    $clock->set('2026-09-19 09:40:00');
    $this->postJson("/v1/tickets/{$ticketId}/comments", ['body' => 'We are looking into it.', 'visibility' => 'public'])->assertCreated();
    $clock->set('2026-09-19 09:50:00');
    $this->postJson("/v1/tickets/{$ticketId}/comments", ['body' => 'A technician is on the way.', 'visibility' => 'public'])->assertCreated();

    $timers = slaTimers($ticketId);
    expect($timers[0]->state)->toBe(TimerState::Met)
        ->and($timers[0]->met_at->toIso8601ZuluString())->toBe('2026-09-19T09:40:00Z')
        ->and($timers[0]->events()->withoutTenancy()->where('type', 'met')->count())->toBe(1)
        ->and($timers[1]->state)->toBe(TimerState::Running);
});

it('recomputes unfinished deadlines from the start when the priority is overridden', function (): void {
    $clock = slaFreeze('2026-09-19 09:00:00');
    $tenant = slaTenant('sla-recompute');
    actingAsRole($tenant, 'admin');
    $ticketId = slaTicket($tenant);

    $clock->set('2026-09-19 09:10:00');
    $this->postJson("/v1/tickets/{$ticketId}/priority", ['level' => 'P1', 'reason' => 'The whole floor cannot print.'])->assertOk();

    $timers = slaTimers($ticketId);
    expect($timers[0]->target_minutes)->toBe(30)
        ->and($timers[0]->started_at->toIso8601ZuluString())->toBe('2026-09-19T09:00:00Z')
        ->and($timers[0]->due_at->toIso8601ZuluString())->toBe('2026-09-19T09:30:00Z')
        ->and($timers[1]->target_minutes)->toBe(240)
        ->and($timers[1]->due_at->toIso8601ZuluString())->toBe('2026-09-19T13:00:00Z')
        ->and($timers[1]->warning_at->toIso8601ZuluString())->toBe('2026-09-19T12:00:00Z');
});
