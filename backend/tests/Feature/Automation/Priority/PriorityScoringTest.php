<?php

declare(strict_types=1);

use App\Modules\Contacts\Enums\OrganizationTier;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Models\Organization;
use App\Modules\Sla\Models\BusinessCalendar;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tickets\Domain\Priority;
use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Events\PriorityChanged;
use App\Modules\Tickets\Models\Category;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketEvent;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

// docs/05-algorithms/priority-scoring.md, end to end: create, edit and the hourly ageing pass.

beforeEach(function (): void {
    // A Monday, 09:00 UTC.
    $this->clock = new FrozenClock('2026-09-21 09:00:00');
    $this->app->instance(Clock::class, $this->clock);
    $this->tenant = createTenant('priority', ['timezone' => 'UTC']);
    $this->category = Category::factory()->forTenant($this->tenant)->create();
});

function priorityContact(Tenant $tenant, ?OrganizationTier $tier): Contact
{
    $organization = $tier === null ? null : Organization::factory()->forTenant($tenant)->tier($tier)->create();

    return Contact::factory()->forTenant($tenant)->create(['organization_id' => $organization?->id]);
}

/** @return array<string, mixed> */
function priorityPayload(Contact $contact, int $impact, int $urgency): array
{
    return [
        'title' => 'Team cannot upload files',
        'description' => 'Uploads fail with a timeout for everyone in the team.',
        'contact_id' => $contact->id,
        'category_id' => test()->category->id,
        'impact' => $impact,
        'urgency' => $urgency,
    ];
}

function priorityTicket(string $id): Ticket
{
    return Ticket::query()->withoutTenancy()->findOrFail($id);
}

it('scores a new ticket like the worked examples', function (int $impact, int $urgency, ?OrganizationTier $tier, float $score, string $level): void {
    actingAsRole($this->tenant, 'agent');

    $response = $this->postJson('/v1/tickets', priorityPayload(priorityContact($this->tenant, $tier), $impact, $urgency))
        ->assertCreated()
        ->assertJsonPath('data.priority_score', fn (int|float $value): bool => (float) $value === $score) // JSON has no 90.0
        ->assertJsonPath('data.priority_level', $level)
        ->assertJsonPath('data.priority_computed_level', $level)
        ->assertJsonPath('data.priority_overridden', false)
        ->assertJsonPath('data.priority_explanation.strategy', 'basic_weighted_priority')
        ->assertJsonPath('data.priority_explanation.strategy_version', '1.0.0')
        ->assertJsonPath('data.priority_explanation.level', $level)
        ->assertJsonPath('data.priority_explanation.effective_level', $level)
        ->assertJsonPath('data.priority_explanation.manual_override', false);

    $explanation = $response->json('data.priority_explanation');
    expect(array_column($explanation['parts'], 'name'))->toBe(['impact', 'urgency', 'tier', 'age'])
        ->and(abs(array_sum(array_column($explanation['parts'], 'contribution')) - $score))->toBeLessThanOrEqual(0.05)
        ->and($explanation['settings']['age_full_hours'])->toEqual(72);

    // The first scoring is part of the creation: no priority_changed history row.
    expect(TicketEvent::query()->withoutTenancy()->where('ticket_id', $response->json('data.id'))->pluck('type')->all())
        ->not->toContain('priority_changed');
})->with([
    'payment system down' => [4, 4, OrganizationTier::Enterprise, 90.0, 'P1'],
    'report export slow' => [3, 2, OrganizationTier::Standard, 38.3, 'P3'],
    'typo, contact without organisation' => [1, 1, null, 0.0, 'P4'],
]);

it('scores again when impact or urgency is edited and records the level change', function (): void {
    $user = actingAsRole($this->tenant, 'agent');
    $id = $this->postJson('/v1/tickets', priorityPayload(priorityContact($this->tenant, null), 1, 1))
        ->assertCreated()->json('data.id');

    $this->clock->advance('PT30M');
    Event::fake([PriorityChanged::class]);

    $this->patchJson("/v1/tickets/{$id}", ['impact' => 4, 'urgency' => 4])->assertOk()
        // 40 + 35 + 0 + 100 × 0.10 × (0.5 ÷ 72) = 75.07, which is 75.1.
        ->assertJsonPath('data.priority_score', 75.1)
        ->assertJsonPath('data.priority_level', 'P1');

    $event = TicketEvent::query()->withoutTenancy()->where('ticket_id', $id)->where('type', 'priority_changed')->sole();
    expect($event->getAttribute('actor_type'))->toBe('user')
        ->and($event->getAttribute('actor_id'))->toBe($user->id)
        ->and($event->getAttribute('old_values'))->toBe(['priority' => 'P4'])
        ->and($event->getAttribute('new_values'))->toEqual(['priority' => 'P1', 'score' => 75.1]);
    Event::assertDispatched(PriorityChanged::class, fn (PriorityChanged $changed): bool => $changed->ticketId === $id);

    // A title edit is not a priority input.
    $this->clock->advance('PT10H');
    $this->patchJson("/v1/tickets/{$id}", ['title' => 'Renamed'])->assertOk()->assertJsonPath('data.priority_score', 75.1);
});

it('ages active tickets on the hourly pass: 47.5 after 24 h, P2 after 72 h', function (): void {
    actingAsRole($this->tenant, 'agent');
    $id = $this->postJson('/v1/tickets', priorityPayload(priorityContact($this->tenant, OrganizationTier::Premium), 2, 3))
        ->assertCreated()->assertJsonPath('data.priority_score', 44.2)->json('data.id');

    $updatedAt = priorityTicket($id)->updated_at;

    $this->clock->set('2026-09-22 09:00:00');
    $this->artisan('tickets:reevaluate-priority')->assertSuccessful();

    $ticket = priorityTicket($id);
    expect((float) $ticket->priority_score)->toBe(47.5)
        ->and($ticket->priority_level)->toBe(Priority::P3)
        // A refreshed score is not an edit.
        ->and($ticket->updated_at->equalTo($updatedAt))->toBeTrue()
        ->and(TicketEvent::query()->withoutTenancy()->where('ticket_id', $id)->where('type', 'priority_changed')->exists())->toBeFalse();

    $this->clock->set('2026-09-24 09:00:00');
    $this->artisan('tickets:reevaluate-priority', ['--tenant' => 'priority'])->assertSuccessful();

    $ticket = priorityTicket($id);
    $event = TicketEvent::query()->withoutTenancy()->where('ticket_id', $id)->where('type', 'priority_changed')->sole();
    expect((float) $ticket->priority_score)->toBe(54.2)
        ->and($ticket->priority_level)->toBe(Priority::P2)
        ->and($ticket->updated_at->toDateTimeString())->toBe('2026-09-24 09:00:00')
        ->and($event->getAttribute('actor_type'))->toBe('system')
        ->and($event->getAttribute('actor_id'))->toBeNull()
        ->and($event->getAttribute('created_at')->toDateTimeString())->toBe('2026-09-24 09:00:00');
});

it('ages only active tickets, in every active workspace or only the named one', function (): void {
    $other = createTenant('priority-b', ['timezone' => 'UTC']);
    $make = fn (Tenant $tenant, TicketStatus $status): Ticket => Ticket::factory()->forTenant($tenant)->create([
        'status' => $status,
        'impact' => 1,
        'urgency' => 1,
        'priority_score' => 0,
        'priority_level' => Priority::P4,
        'priority_explanation' => ['strategy' => 'stale'],
        'resolved_at' => $status->isActive() ? null : '2026-09-21 09:00:00',
        'closed_at' => $status === TicketStatus::Closed ? '2026-09-21 09:00:00' : null,
        'created_at' => '2026-09-21 09:00:00',
    ]);
    $tickets = collect(TicketStatus::cases())->mapWithKeys(fn (TicketStatus $status): array => [$status->value => $make($this->tenant, $status)]);
    $foreign = $make($other, TicketStatus::Open);

    $this->clock->set('2026-09-24 09:00:00'); // 72 h later: the age part is 10.0
    $this->artisan('tickets:reevaluate-priority', ['--tenant' => 'priority'])
        ->expectsOutputToContain('Reevaluated 4 active ticket priorities.')->assertSuccessful();

    foreach ($tickets as $status => $ticket) {
        $active = TicketStatus::from($status)->isActive();
        expect((float) priorityTicket($ticket->id)->priority_score)->toBe($active ? 10.0 : 0.0, $status)
            ->and(priorityTicket($ticket->id)->priority_explanation['strategy'])->toBe($active ? 'basic_weighted_priority' : 'stale', $status);
    }
    expect(array_map(fn (TicketStatus $status): string => $status->value, TicketStatus::active()))
        ->toBe(['open', 'assigned', 'in_progress', 'pending'])
        ->and((float) priorityTicket($foreign->id)->priority_score)->toBe(0.0);

    $this->artisan('tickets:reevaluate-priority')->expectsOutputToContain('Reevaluated 5 active ticket priorities.')->assertSuccessful();
    expect((float) priorityTicket($foreign->id)->priority_score)->toBe(10.0);
});

it('measures waiting time on the default business calendar and resolves it once per workspace', function (): void {
    BusinessCalendar::factory()->forTenant($this->tenant)->create([
        'timezone' => 'UTC',
        'is_default' => true,
        'weekly_hours' => array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri'], [['09:00', '17:00']]),
    ]);
    $contact = priorityContact($this->tenant, OrganizationTier::Premium);
    $tickets = Ticket::factory()->forContact($contact, $this->category)->count(3)->create([
        'impact' => 2, 'urgency' => 3, 'created_at' => '2026-09-21 09:00:00',
    ]);

    $this->clock->set('2026-09-22 09:00:00'); // 24 h on the wall, 8 business hours
    DB::enableQueryLog();
    $this->artisan('tickets:reevaluate-priority')->assertSuccessful();
    $calendarReads = array_filter(array_column(DB::getQueryLog(), 'query'), fn (string $sql): bool => str_contains($sql, 'from "business_calendars"'));
    DB::disableQueryLog();

    // 13.33 + 23.33 + 7.5 + 100 × 0.10 × (8 ÷ 72) = 45.28; on a 24×7 calendar it would be 47.5.
    foreach ($tickets as $ticket) {
        expect((float) priorityTicket($ticket->id)->priority_score)->toBe(45.3);
    }
    // One lookup of the default calendar id and one load of the calendar, not one per ticket.
    expect(count($calendarReads))->toBeLessThanOrEqual(2);
});
