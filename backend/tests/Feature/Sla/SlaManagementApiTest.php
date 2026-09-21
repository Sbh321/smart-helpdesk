<?php

declare(strict_types=1);

use App\Modules\Platform\Actions\ProvisionTenant;
use App\Modules\Sla\Models\BusinessCalendar;
use App\Modules\Sla\Models\CalendarHoliday;
use App\Modules\Sla\Models\SlaPolicy;
use App\Modules\Sla\Models\TicketSlaTimer;
use App\Modules\Tenancy\Models\Tenant;

/*
 * Policy, Business calendar and holiday management plus the ticket timer read route
 * (docs/04-domain/sla.md). Permissions: `sla.manage`, `calendars.manage`, `tickets.view`.
 */

const OFFICE_HOURS = [
    'mon' => [['09:00', '12:00'], ['13:00', '17:00']],
    'tue' => [['09:00', '17:00']],
    'wed' => [['09:00', '17:00']],
];

/** @return list<array{priority_level: string, first_response_minutes: int, resolution_minutes: int}> */
function slaTargetsPayload(int $factor = 1): array
{
    return [
        ['priority_level' => 'P1', 'first_response_minutes' => 10 * $factor, 'resolution_minutes' => 60 * $factor],
        ['priority_level' => 'P2', 'first_response_minutes' => 20 * $factor, 'resolution_minutes' => 120 * $factor],
        ['priority_level' => 'P3', 'first_response_minutes' => 30 * $factor, 'resolution_minutes' => 180 * $factor],
        ['priority_level' => 'P4', 'first_response_minutes' => 40 * $factor, 'resolution_minutes' => 240 * $factor],
    ];
}

function slaProvisioned(string $slug): Tenant
{
    return app(ProvisionTenant::class)($slug, 'SLA '.$slug)['tenant'];
}

/** A calendar with one unfinished timer counting on it. */
function slaCalendarInUse(Tenant $tenant, string $state = 'running'): BusinessCalendar
{
    $calendar = BusinessCalendar::factory()->forTenant($tenant)->create(['timezone' => 'UTC', 'weekly_hours' => OFFICE_HOURS]);
    TicketSlaTimer::factory()->forTenant($tenant)->create([
        'calendar_id' => $calendar->id,
        'state' => $state,
        'met_at' => $state === 'met' ? now() : null,
    ]);

    return $calendar;
}

describe('business calendars', function (): void {
    it('creates, reads, updates and deletes a calendar with holidays', function (): void {
        $tenant = slaProvisioned('cal-crud');
        actingAsRole($tenant, 'admin');

        $id = $this->postJson('/v1/calendars', ['name' => 'Office', 'timezone' => 'Asia/Kathmandu', 'weekly_hours' => OFFICE_HOURS])
            ->assertCreated()
            ->assertJsonPath('data.timezone', 'Asia/Kathmandu')
            ->assertJsonPath('data.weekly_hours.mon.1.0', '13:00')
            ->assertJsonPath('data.is_default', false)
            ->assertJsonPath('data.holidays', [])
            ->json('data.id');

        $this->getJson('/v1/calendars')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->patchJson("/v1/calendars/{$id}", ['name' => 'Head office', 'is_default' => true])
            ->assertOk()->assertJsonPath('data.name', 'Head office')->assertJsonPath('data.is_default', true);

        $holidayId = $this->postJson("/v1/calendars/{$id}/holidays", ['date' => '2026-10-20', 'name' => 'Dashain', 'recurs_yearly' => false])
            ->assertOk()
            ->assertJsonPath('data.holidays.0.date', '2026-10-20')
            ->assertJsonPath('data.holidays.0.name', 'Dashain')
            ->json('data.holidays.0.id');
        $this->postJson("/v1/calendars/{$id}/holidays", ['date' => '2026-10-20', 'name' => 'Again'])
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->getJson("/v1/calendars/{$id}")->assertOk()->assertJsonCount(1, 'data.holidays');
        $this->deleteJson("/v1/calendars/{$id}/holidays/{$holidayId}")->assertNoContent();

        // The default calendar cannot be deleted; another one takes over first.
        $this->deleteJson("/v1/calendars/{$id}")->assertStatus(409)
            ->assertJsonPath('code', 'in_use')->assertJsonPath('meta.used_by', ['default_calendar']);
        $this->postJson('/v1/calendars', ['name' => 'Second', 'timezone' => 'UTC', 'weekly_hours' => OFFICE_HOURS, 'is_default' => true])->assertCreated();
        $this->deleteJson("/v1/calendars/{$id}")->assertNoContent();
        expect(BusinessCalendar::query()->withoutTenancy()->whereKey($id)->exists())->toBeFalse();
    });

    it('validates calendar input', function (array $payload, string $field): void {
        $tenant = slaProvisioned('cal-invalid');
        actingAsRole($tenant, 'admin');

        $this->postJson('/v1/calendars', ['name' => 'Office', 'timezone' => 'UTC', 'weekly_hours' => OFFICE_HOURS, ...$payload])
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonStructure(['errors' => [$field]]);
    })->with([
        'unknown time zone' => [['timezone' => 'Mars/Olympus'], 'timezone'],
        'no working window' => [['weekly_hours' => []], 'weekly_hours'],
        'overlapping windows' => [['weekly_hours' => ['mon' => [['09:00', '13:00'], ['12:00', '17:00']]]], 'weekly_hours'],
        'window ends before it starts' => [['weekly_hours' => ['mon' => [['17:00', '09:00']]]], 'weekly_hours'],
        'missing name' => [['name' => ''], 'name'],
    ]);

    it('rejects a duplicate calendar name whatever the case', function (): void {
        $tenant = slaProvisioned('cal-dup');
        BusinessCalendar::factory()->forTenant($tenant)->create(['name' => 'Office']);
        actingAsRole($tenant, 'admin');

        $this->postJson('/v1/calendars', ['name' => 'office', 'timezone' => 'UTC', 'weekly_hours' => OFFICE_HOURS])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['name']]);
    });

    it('accepts unchanged hours in another order while timers are unfinished, and answers 409 in_use for changed ones', function (): void {
        $tenant = slaProvisioned('cal-guard');
        $calendar = slaCalendarInUse($tenant);
        actingAsRole($tenant, 'admin');

        $reordered = [
            'wed' => [['09:00', '17:00']],
            'tue' => [['09:00', '17:00']],
            'mon' => [['13:00', '17:00'], ['09:00', '12:00']],
            'sat' => [],
        ];
        $this->patchJson("/v1/calendars/{$calendar->id}", ['name' => 'Renamed', 'timezone' => 'UTC', 'weekly_hours' => $reordered])
            ->assertOk()->assertJsonPath('data.name', 'Renamed');

        $this->patchJson("/v1/calendars/{$calendar->id}", ['weekly_hours' => [...OFFICE_HOURS, 'thu' => [['09:00', '17:00']]]])
            ->assertStatus(409)
            ->assertJsonPath('code', 'in_use')
            ->assertJsonPath('meta.record', 'business_calendar')
            ->assertJsonPath('meta.used_by', ['ticket_sla_timers']);
        $this->patchJson("/v1/calendars/{$calendar->id}", ['timezone' => 'Asia/Kathmandu'])
            ->assertStatus(409)->assertJsonPath('code', 'in_use');
        $this->postJson("/v1/calendars/{$calendar->id}/holidays", ['date' => '2026-10-20', 'name' => 'Dashain'])
            ->assertStatus(409)->assertJsonPath('code', 'in_use');
        $this->deleteJson("/v1/calendars/{$calendar->id}")
            ->assertStatus(409)->assertJsonPath('meta.used_by', ['ticket_sla_timers']);
    });

    it('lets hours change once every timer on the calendar is finished, but still keeps the calendar', function (): void {
        $tenant = slaProvisioned('cal-finished');
        $calendar = slaCalendarInUse($tenant, 'met');
        actingAsRole($tenant, 'admin');

        $this->patchJson("/v1/calendars/{$calendar->id}", ['weekly_hours' => ['fri' => [['10:00', '15:00']]]])
            ->assertOk()->assertJsonPath('data.weekly_hours.fri.0.1', '15:00');
        $this->deleteJson("/v1/calendars/{$calendar->id}")->assertStatus(409)->assertJsonPath('code', 'in_use');
    });

    it('refuses to delete a calendar that a policy uses', function (): void {
        $tenant = slaProvisioned('cal-policy');
        $calendar = BusinessCalendar::factory()->forTenant($tenant)->create();
        SlaPolicy::factory()->forTenant($tenant)->create(['calendar_id' => $calendar->id]);
        actingAsRole($tenant, 'admin');

        $this->deleteJson("/v1/calendars/{$calendar->id}")
            ->assertStatus(409)->assertJsonPath('code', 'in_use')->assertJsonPath('meta.used_by', ['sla_policies']);
    });
});

describe('SLA policies', function (): void {
    it('creates, reads, updates and deletes a policy with its four targets', function (): void {
        $tenant = slaProvisioned('pol-crud');
        $calendar = BusinessCalendar::factory()->forTenant($tenant)->create();
        actingAsRole($tenant, 'admin');

        $id = $this->postJson('/v1/sla-policies', [
            'name' => 'Premium', 'applies_to_tier' => 'premium', 'warning_fraction' => 0.5,
            'calendar_id' => $calendar->id, 'targets' => slaTargetsPayload(),
        ])->assertCreated()
            ->assertJsonPath('data.applies_to_tier', 'premium')
            ->assertJsonPath('data.warning_fraction', 0.5)
            ->assertJsonPath('data.calendar_id', $calendar->id)
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.targets.0', ['priority_level' => 'P1', 'first_response_minutes' => 10, 'resolution_minutes' => 60])
            ->assertJsonPath('data.targets.3.priority_level', 'P4')
            ->json('data.id');

        $this->getJson('/v1/sla-policies')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/v1/sla-policies/{$id}")->assertOk()->assertJsonPath('data.name', 'Premium');

        $this->patchJson("/v1/sla-policies/{$id}", ['name' => 'Premium plus', 'targets' => slaTargetsPayload(2)])
            ->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.targets.2.resolution_minutes', 360)
            ->assertJsonCount(4, 'data.targets');

        $this->deleteJson("/v1/sla-policies/{$id}")->assertNoContent();
        expect(SlaPolicy::query()->withoutTenancy()->whereKey($id)->exists())->toBeFalse();
    });

    it('validates policy input', function (array $payload, string $field): void {
        $tenant = slaProvisioned('pol-invalid');
        actingAsRole($tenant, 'admin');

        $this->postJson('/v1/sla-policies', ['name' => 'Premium', 'warning_fraction' => 0.75, 'targets' => slaTargetsPayload(), ...$payload])
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonStructure(['errors' => [$field]]);
    })->with([
        'three targets only' => [['targets' => array_slice(slaTargetsPayload(), 0, 3)], 'targets'],
        'a priority twice' => [['targets' => [...array_slice(slaTargetsPayload(), 0, 3), slaTargetsPayload()[0]]], 'targets.0.priority_level'],
        'zero minutes' => [['targets' => [['priority_level' => 'P1', 'first_response_minutes' => 0, 'resolution_minutes' => 60], ...array_slice(slaTargetsPayload(), 1)]], 'targets.0.first_response_minutes'],
        'warning fraction of one' => [['warning_fraction' => 1], 'warning_fraction'],
        'unknown tier' => [['applies_to_tier' => 'gold'], 'applies_to_tier'],
        'name of the seeded default policy' => [['name' => 'default'], 'name'],
        'unknown calendar' => [['calendar_id' => '01990000-0000-7000-8000-000000000000'], 'calendar_id'],
    ]);

    it('rejects a calendar of another tenant and a second policy for one tier', function (): void {
        $tenant = slaProvisioned('pol-foreign');
        $foreign = BusinessCalendar::factory()->forTenant(createTenant('pol-foreign-other'))->create();
        SlaPolicy::factory()->forTenant($tenant)->create(['applies_to_tier' => 'enterprise']);
        actingAsRole($tenant, 'admin');

        $this->postJson('/v1/sla-policies', ['name' => 'A', 'warning_fraction' => 0.75, 'calendar_id' => $foreign->id, 'targets' => slaTargetsPayload()])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['calendar_id']]);
        $this->postJson('/v1/sla-policies', ['name' => 'B', 'warning_fraction' => 0.75, 'applies_to_tier' => 'enterprise', 'targets' => slaTargetsPayload()])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['applies_to_tier']]);
    });

    it('moves the default flag and never leaves the tenant without a default', function (): void {
        $tenant = slaProvisioned('pol-default');
        actingAsRole($tenant, 'admin');
        $seededId = $tenant->run(fn (): string => SlaPolicy::query()->where('is_default', true)->sole()->id);

        $this->patchJson("/v1/sla-policies/{$seededId}", ['is_default' => false])
            ->assertStatus(409)->assertJsonPath('code', 'in_use')->assertJsonPath('meta.used_by', ['default_policy']);
        $this->deleteJson("/v1/sla-policies/{$seededId}")
            ->assertStatus(409)->assertJsonPath('code', 'in_use')->assertJsonPath('meta.used_by', ['default_policy']);

        $newId = $this->postJson('/v1/sla-policies', ['name' => 'New default', 'is_default' => true, 'warning_fraction' => 0.8, 'targets' => slaTargetsPayload()])
            ->assertCreated()->json('data.id');
        $defaults = $tenant->run(fn (): array => SlaPolicy::query()->where('is_default', true)->pluck('id')->all());
        expect($defaults)->toBe([$newId]);
        $this->deleteJson("/v1/sla-policies/{$seededId}")->assertNoContent();
    });

    it('refuses to delete a policy that timers reference', function (): void {
        $tenant = slaProvisioned('pol-used');
        $policy = SlaPolicy::factory()->forTenant($tenant)->create();
        TicketSlaTimer::factory()->forTenant($tenant)->create(['policy_id' => $policy->id, 'state' => 'met', 'met_at' => now()]);
        actingAsRole($tenant, 'admin');

        $this->deleteJson("/v1/sla-policies/{$policy->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'in_use')
            ->assertJsonPath('meta.record', 'sla_policy')
            ->assertJsonPath('meta.used_by', ['ticket_sla_timers']);
    });
});

describe('tenancy and permissions', function (): void {
    it('answers 404 for records of another tenant', function (): void {
        $tenant = slaProvisioned('sla-mine');
        $other = createTenant('sla-theirs');
        $calendar = BusinessCalendar::factory()->forTenant($other)->create();
        $holiday = CalendarHoliday::factory()->forTenant($other)->create(['calendar_id' => $calendar->id]);
        $policy = SlaPolicy::factory()->forTenant($other)->create();
        $timer = TicketSlaTimer::factory()->forTenant($other)->create();
        actingAsRole($tenant, 'admin');

        $this->getJson("/v1/calendars/{$calendar->id}")->assertNotFound();
        $this->patchJson("/v1/calendars/{$calendar->id}", ['name' => 'Mine now'])->assertNotFound();
        $this->deleteJson("/v1/calendars/{$calendar->id}")->assertNotFound();
        $this->postJson("/v1/calendars/{$calendar->id}/holidays", ['date' => '2026-10-20', 'name' => 'Dashain'])->assertNotFound();
        $this->deleteJson("/v1/calendars/{$calendar->id}/holidays/{$holiday->id}")->assertNotFound();
        $this->getJson("/v1/sla-policies/{$policy->id}")->assertNotFound();
        $this->patchJson("/v1/sla-policies/{$policy->id}", ['name' => 'Mine now'])->assertNotFound();
        $this->deleteJson("/v1/sla-policies/{$policy->id}")->assertNotFound();
        $this->getJson("/v1/tickets/{$timer->ticket_id}/sla")->assertNotFound();

        expect($other->run(fn () => $calendar->fresh())->name)->not->toBe('Mine now')
            ->and($this->getJson('/v1/calendars')->json('data'))->toBe([]);
    });

    it('answers 404 for a holiday that belongs to another calendar of the tenant', function (): void {
        $tenant = slaProvisioned('sla-holiday');
        $first = BusinessCalendar::factory()->forTenant($tenant)->create();
        $second = BusinessCalendar::factory()->forTenant($tenant)->create();
        $holiday = CalendarHoliday::factory()->forTenant($tenant)->create(['calendar_id' => $second->id]);
        actingAsRole($tenant, 'admin');

        $this->deleteJson("/v1/calendars/{$first->id}/holidays/{$holiday->id}")->assertNotFound();
    });

    it('lets an agent read but not manage policies and calendars', function (): void {
        $tenant = slaProvisioned('sla-agent');
        $calendar = BusinessCalendar::factory()->forTenant($tenant)->create();
        $policy = SlaPolicy::factory()->forTenant($tenant)->create();
        actingAsRole($tenant, 'agent');

        $this->getJson('/v1/calendars')->assertOk();
        $this->getJson("/v1/sla-policies/{$policy->id}")->assertOk();

        $this->postJson('/v1/calendars', ['name' => 'Office', 'timezone' => 'UTC', 'weekly_hours' => OFFICE_HOURS])->assertForbidden();
        $this->patchJson("/v1/calendars/{$calendar->id}", ['name' => 'Office'])->assertForbidden();
        $this->deleteJson("/v1/calendars/{$calendar->id}")->assertForbidden();
        $this->postJson("/v1/calendars/{$calendar->id}/holidays", ['date' => '2026-10-20', 'name' => 'Dashain'])->assertForbidden();
        $this->postJson('/v1/sla-policies', ['name' => 'P', 'warning_fraction' => 0.75, 'targets' => slaTargetsPayload()])->assertForbidden();
        $this->patchJson("/v1/sla-policies/{$policy->id}", ['name' => 'P'])->assertForbidden();
        $this->deleteJson("/v1/sla-policies/{$policy->id}")->assertForbidden()->assertJsonPath('code', 'forbidden');
    });

    it('gives managers both management permissions and requires a session', function (): void {
        $tenant = slaProvisioned('sla-manager');
        $this->getJson('/v1/sla-policies')->assertUnauthorized();

        actingAsRole($tenant, 'manager');
        $this->postJson('/v1/calendars', ['name' => 'Office', 'timezone' => 'UTC', 'weekly_hours' => OFFICE_HOURS])->assertCreated();
        $this->postJson('/v1/sla-policies', ['name' => 'Enterprise', 'applies_to_tier' => 'enterprise', 'warning_fraction' => 0.75, 'targets' => slaTargetsPayload()])
            ->assertCreated();
    });
});

describe('GET /v1/tickets/{ticket}/sla', function (): void {
    it('lists the ticket timers with enum values and no client-side guesswork', function (): void {
        $tenant = slaProvisioned('sla-read');
        $first = TicketSlaTimer::factory()->forTenant($tenant)->create([
            'kind' => 'first_response', 'state' => 'met', 'met_at' => '2026-09-19 09:40:00',
            'started_at' => '2026-09-19 09:00:00', 'warning_at' => '2026-09-19 09:45:00', 'due_at' => '2026-09-19 10:00:00',
        ]);
        foreach ([[1, 'met'], [2, 'paused']] as [$cycle, $state]) {
            TicketSlaTimer::factory()->forTenant($tenant)->create([
                'ticket_id' => $first->ticket_id, 'policy_id' => $first->policy_id, 'kind' => 'resolution', 'cycle' => $cycle,
                'state' => $state, 'paused_at' => $state === 'paused' ? '2026-09-19 10:00:00' : null,
                'paused_from_state' => $state === 'paused' ? 'running' : null,
            ]);
        }
        actingAsRole($tenant, 'agent');

        $this->getJson("/v1/tickets/{$first->ticket_id}/sla")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.kind', 'first_response')
            ->assertJsonPath('data.0.state', 'met')
            ->assertJsonPath('data.0.due_at', '2026-09-19T10:00:00.000000Z')
            ->assertJsonPath('data.0.target_minutes', 60)
            ->assertJsonPath('data.0.warning_fraction', 0.75)
            ->assertJsonPath('data.0.strategy', 'simple_sla_timer')
            ->assertJsonPath('data.1.cycle', 1)
            ->assertJsonPath('data.2.cycle', 2)
            ->assertJsonPath('data.2.state', 'paused')
            ->assertJsonMissingPath('data.0.remaining_seconds')
            ->assertJsonMissingPath('data.0.tenant_id');
    });
});
