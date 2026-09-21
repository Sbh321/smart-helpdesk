<?php

declare(strict_types=1);

use App\Modules\Platform\Actions\ProvisionTenant;
use App\Modules\Sla\Models\BusinessCalendar;
use App\Modules\Sla\Models\SlaEvent;
use App\Modules\Sla\Models\SlaPolicy;
use App\Modules\Sla\Models\TicketSlaTimer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('stores tenant business calendars and SLA policies as separate tables', function (): void {
    expect(Schema::hasTable('business_calendars'))->toBeTrue()
        ->and(Schema::hasTable('calendar_holidays'))->toBeTrue()
        ->and(Schema::hasTable('sla_policies'))->toBeTrue()
        ->and(Schema::hasTable('sla_targets'))->toBeTrue()
        ->and(Schema::hasTable('ticket_sla_timers'))->toBeTrue()
        ->and(Schema::hasTable('sla_events'))->toBeTrue();
});

it('provisions one default SLA policy with the four documented targets idempotently', function (): void {
    $provision = app(ProvisionTenant::class);
    $tenant = $provision('sla-default', 'SLA Default')['tenant'];
    $provision('sla-default', 'SLA Default');

    $tenant->run(function (): void {
        $policy = SlaPolicy::query()->where('is_default', true)->sole();

        expect($policy->calendar_id)->toBeNull()
            ->and($policy->targets()->orderBy('priority_level')->get([
                'priority_level', 'first_response_minutes', 'resolution_minutes',
            ])->toArray())->toBe([
                ['priority_level' => 'P1', 'first_response_minutes' => 30, 'resolution_minutes' => 240],
                ['priority_level' => 'P2', 'first_response_minutes' => 60, 'resolution_minutes' => 480],
                ['priority_level' => 'P3', 'first_response_minutes' => 240, 'resolution_minutes' => 1440],
                ['priority_level' => 'P4', 'first_response_minutes' => 480, 'resolution_minutes' => 4320],
            ]);
    });
});

it('rejects a policy referencing another tenant calendar', function (): void {
    $owner = createTenant('sla-owner');
    $other = createTenant('sla-other');
    $foreignCalendar = BusinessCalendar::factory()->forTenant($other)->create();

    expect(fn () => SlaPolicy::factory()->forTenant($owner)->create([
        'calendar_id' => $foreignCalendar->id,
    ]))->toThrow(QueryException::class);
});

it('indexes pending warnings and breaches for the SLA sweep', function (): void {
    $indexes = DB::table('pg_indexes')
        ->where('tablename', 'ticket_sla_timers')
        ->pluck('indexdef', 'indexname');

    expect($indexes)->toHaveKeys([
        'ticket_sla_timers_due_pidx',
        'ticket_sla_timers_warning_pidx',
    ])->and($indexes['ticket_sla_timers_due_pidx'])->toContain('due_at')
        ->and($indexes['ticket_sla_timers_warning_pidx'])->toContain('warning_at');
});

it('allows one unfinished timer per ticket and kind, and any number of finished cycles', function (): void {
    $tenant = createTenant('sla-unfinished');
    $first = TicketSlaTimer::factory()->forTenant($tenant)->create(['kind' => 'resolution', 'state' => 'met', 'met_at' => now()]);
    $same = ['ticket_id' => $first->ticket_id, 'policy_id' => $first->policy_id, 'kind' => 'resolution'];
    TicketSlaTimer::factory()->forTenant($tenant)->create([...$same, 'cycle' => 2, 'state' => 'cancelled', 'cancelled_at' => now()]);
    TicketSlaTimer::factory()->forTenant($tenant)->create([...$same, 'cycle' => 3, 'state' => 'breached', 'breached_at' => now()]);
    // The other kind has its own slot.
    TicketSlaTimer::factory()->forTenant($tenant)->create([...$same, 'kind' => 'first_response', 'state' => 'running']);

    $index = DB::table('pg_indexes')->where('indexname', 'ticket_sla_timers_one_unfinished_key')->value('indexdef');
    expect($index)->toContain('UNIQUE')->toContain('(tenant_id, ticket_id, kind)')->toContain("'met'")->toContain("'cancelled'");

    TicketSlaTimer::factory()->forTenant($tenant)->create([...$same, 'cycle' => 4, 'state' => 'paused']);
})->throws(QueryException::class, 'ticket_sla_timers_one_unfinished_key');

it('keeps sla_events append-only for the runtime database role', function (string $statement): void {
    $event = SlaEvent::factory()->forTenant(createTenant('sla-append'))->create();

    DB::statement(str_replace(':id', "'{$event->id}'", $statement));
})->with([
    'update' => ["UPDATE sla_events SET type = 'met' WHERE id = :id"],
    'delete' => ['DELETE FROM sla_events WHERE id = :id'],
    'truncate' => ['TRUNCATE sla_events'],
])->throws(QueryException::class, 'permission denied');
