<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Tickets\Domain\Priority;
use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Events\PriorityChanged;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketEvent;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Illuminate\Support\Facades\Event;

// POST /v1/tickets/{ticket}/priority: a manual level always wins (docs/05-algorithms/priority-scoring.md).

beforeEach(function (): void {
    $this->clock = new FrozenClock('2026-09-21 09:00:00');
    $this->app->instance(Clock::class, $this->clock);
    $this->tenant = createTenant('override', ['timezone' => 'UTC']);
});

/** @param array<string, mixed> $attributes */
function overrideTicket(array $attributes = []): Ticket
{
    // impact 1, urgency 1, standard tier, 0 h: score 0.0, P4.
    return Ticket::factory()->forTenant(test()->tenant)->create([
        'impact' => 1,
        'urgency' => 1,
        'priority_score' => 0,
        'priority_level' => Priority::P4,
        'priority_explanation' => ['strategy' => 'basic_weighted_priority', 'strategy_version' => '1.0.0', 'level' => 'P4', 'effective_level' => 'P4', 'manual_override' => false],
        'created_at' => '2026-09-21 09:00:00',
        ...$attributes,
    ]);
}

function overriddenTicket(Ticket $ticket): Ticket
{
    return Ticket::query()->withoutTenancy()->findOrFail($ticket->id);
}

it('overrides the level with a reason, writes history and an audit entry', function (): void {
    $user = actingAsRole($this->tenant, 'agent');
    $ticket = overrideTicket();
    $version = overriddenTicket($ticket)->version;
    Event::fake([PriorityChanged::class]);

    $this->postJson("/v1/tickets/{$ticket->id}/priority", ['level' => 'P1', 'reason' => 'VIP outage'])
        ->assertOk()
        ->assertJsonPath('data.priority_level', 'P1')
        ->assertJsonPath('data.priority_computed_level', 'P4')
        ->assertJsonPath('data.priority_overridden', true)
        ->assertJsonPath('data.priority_override_reason', 'VIP outage')
        ->assertJsonPath('data.priority_explanation.strategy', 'basic_weighted_priority')
        ->assertJsonPath('data.priority_explanation.level', 'P4')
        ->assertJsonPath('data.priority_explanation.effective_level', 'P1')
        ->assertJsonPath('data.priority_explanation.manual_override', true)
        ->assertJsonPath('data.updated_at', '2026-09-21T09:00:00Z');

    $stored = overriddenTicket($ticket);
    expect($stored->priority_override_level)->toBe(Priority::P1)
        ->and($stored->getAttribute('priority_override_by'))->toBe($user->id)
        ->and($stored->version)->toBe($version + 1);

    $event = TicketEvent::query()->withoutTenancy()->where('ticket_id', $ticket->id)->where('type', 'priority_overridden')->sole();
    expect($event->getAttribute('actor_type'))->toBe('user')
        ->and($event->getAttribute('actor_id'))->toBe($user->id)
        ->and($event->getAttribute('old_values'))->toBe(['priority' => 'P4'])
        ->and($event->getAttribute('new_values'))->toEqual(['priority' => 'P1', 'reason' => 'VIP outage'])
        ->and($event->getAttribute('note'))->toBe('VIP outage');

    $audit = AuditLog::query()->where('action', 'ticket.priority_overridden')->where('subject_id', $ticket->id)->sole();
    expect($audit->tenant_id)->toBe($this->tenant->id)
        ->and($audit->actor_id)->toBe($user->id)
        ->and($audit->changes)->toEqual([
            'old' => ['override_level' => null, 'effective_level' => 'P4'],
            'new' => ['override_level' => 'P1', 'effective_level' => 'P1', 'reason' => 'VIP outage'],
        ])
        ->and($audit->created_at->toDateTimeString())->toBe('2026-09-21 09:00:00');
    Event::assertDispatched(PriorityChanged::class, fn (PriorityChanged $changed): bool => $changed->ticketId === $ticket->id);
});

it('keeps the override through an edit and the ageing pass, then clears it back to the computed level', function (): void {
    actingAsRole($this->tenant, 'agent');
    $ticket = overrideTicket();
    $this->postJson("/v1/tickets/{$ticket->id}/priority", ['level' => 'P1', 'reason' => 'VIP outage'])->assertOk();

    // The edit rescores: 100 × (0.40 × 2/3 + 0.35 × 1/3) = 38.3, computed P3, effective still P1.
    $this->patchJson("/v1/tickets/{$ticket->id}", ['impact' => 3, 'urgency' => 2])->assertOk()
        ->assertJsonPath('data.priority_score', 38.3)
        ->assertJsonPath('data.priority_level', 'P1')
        ->assertJsonPath('data.priority_computed_level', 'P3')
        ->assertJsonPath('data.priority_explanation.level', 'P3')
        ->assertJsonPath('data.priority_explanation.effective_level', 'P1')
        ->assertJsonPath('data.priority_explanation.manual_override', true);

    $this->clock->set('2026-09-24 09:00:00'); // 72 h: 48.3, still P3
    $this->artisan('tickets:reevaluate-priority')->assertSuccessful();

    $stored = overriddenTicket($ticket);
    expect((float) $stored->priority_score)->toBe(48.3)
        ->and($stored->effectivePriority())->toBe(Priority::P1)
        ->and($stored->priority_explanation['manual_override'])->toBeTrue()
        // The effective level never moved, so scoring wrote no priority_changed row.
        ->and(TicketEvent::query()->withoutTenancy()->where('ticket_id', $ticket->id)->where('type', 'priority_changed')->exists())->toBeFalse();

    actingAsRole($this->tenant, 'agent');
    $this->postJson("/v1/tickets/{$ticket->id}/priority", ['level' => null])->assertOk()
        ->assertJsonPath('data.priority_level', 'P3')
        ->assertJsonPath('data.priority_overridden', false)
        ->assertJsonPath('data.priority_override_reason', null)
        ->assertJsonPath('data.priority_explanation.effective_level', 'P3')
        ->assertJsonPath('data.priority_explanation.manual_override', false);

    $stored = overriddenTicket($ticket);
    $audits = AuditLog::query()->where('action', 'ticket.priority_overridden')->where('subject_id', $ticket->id)->orderBy('created_at')->get();
    expect($stored->priority_override_level)->toBeNull()
        ->and($stored->getAttribute('priority_override_by'))->toBeNull()
        ->and($audits)->toHaveCount(2)
        ->and($audits[1]->changes)->toEqual([
            'old' => ['override_level' => 'P1', 'effective_level' => 'P1'],
            'new' => ['override_level' => null, 'effective_level' => 'P3', 'reason' => null],
        ])
        ->and(TicketEvent::query()->withoutTenancy()->where('ticket_id', $ticket->id)->where('type', 'priority_overridden')->count())->toBe(2);
});

it('validates the override body', function (array $body, string $field): void {
    actingAsRole($this->tenant, 'agent');
    $ticket = overrideTicket();

    $this->postJson("/v1/tickets/{$ticket->id}/priority", $body)->assertUnprocessable()->assertJsonValidationErrors($field);

    expect(overriddenTicket($ticket)->priority_override_level)->toBeNull()
        ->and(AuditLog::query()->where('action', 'ticket.priority_overridden')->exists())->toBeFalse();
})->with([
    'reason is required with a level' => [['level' => 'P1'], 'reason'],
    'empty reason' => [['level' => 'P1', 'reason' => ''], 'reason'],
    'level must be present' => [['reason' => 'No level key'], 'level'],
    'unknown level' => [['level' => 'P5', 'reason' => 'Typo'], 'level'],
    'reason too long' => [['level' => 'P2', 'reason' => str_repeat('a', 256)], 'reason'],
]);

it('refuses a resolved or closed ticket with 409 conflict', function (TicketStatus $status): void {
    actingAsRole($this->tenant, 'agent');
    $ticket = overrideTicket(['status' => $status, 'resolved_at' => '2026-09-21 09:00:00']);

    $this->postJson("/v1/tickets/{$ticket->id}/priority", ['level' => 'P1', 'reason' => 'Too late'])
        ->assertConflict()
        ->assertJsonPath('code', 'conflict')
        ->assertJsonPath('meta.status', $status->value);

    expect(overriddenTicket($ticket)->priority_override_level)->toBeNull();
})->with([TicketStatus::Resolved, TicketStatus::Closed]);

it('answers 403 without tickets.update and 404 for a ticket of another workspace', function (): void {
    $ticket = overrideTicket();
    $foreign = Ticket::factory()->forTenant(createTenant('override-b'))->create();

    actingAsRole($this->tenant, 'developer');
    $this->postJson("/v1/tickets/{$ticket->id}/priority", ['level' => 'P1', 'reason' => 'No permission'])
        ->assertForbidden()->assertJsonPath('code', 'forbidden');

    actingAsRole($this->tenant, 'agent');
    $this->postJson("/v1/tickets/{$foreign->id}/priority", ['level' => 'P1', 'reason' => 'Not mine'])->assertNotFound();

    expect(overriddenTicket($ticket)->priority_override_level)->toBeNull()
        ->and(overriddenTicket($foreign)->priority_override_level)->toBeNull()
        ->and(AuditLog::query()->where('action', 'ticket.priority_overridden')->exists())->toBeFalse();
});
