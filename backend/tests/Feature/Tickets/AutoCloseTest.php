<?php

declare(strict_types=1);

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Settings\Settings;
use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Events\TicketLifecycleChanged;
use App\Modules\Tickets\Events\TicketStatusChanged;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketEvent;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;

// tickets:auto-close (docs/11-operations/scheduler.md): resolved tickets close after the workspace's period.

beforeEach(function (): void {
    $this->app->instance(Clock::class, new FrozenClock('2026-09-21 03:10:00'));
    $this->tenant = createTenant('closing', ['timezone' => 'UTC']);
    $this->resolved = fn (Tenant $tenant, string $at, array $attributes = []): Ticket => Ticket::factory()->forTenant($tenant)->create([
        'status' => TicketStatus::Resolved, 'resolved_at' => $at, ...$attributes,
    ]);
    // Read inside the ticket's own workspace (row-level security).
    $this->fresh = fn (Ticket $ticket): Ticket => findInAnyTenant(Ticket::class, $ticket->id);
});

it('closes tickets resolved longer ago than the period, as the system, with history and events', function (): void {
    $old = ($this->resolved)($this->tenant, '2026-09-14 03:00:00'); // 7 days and 10 minutes ago
    $recent = ($this->resolved)($this->tenant, '2026-09-15 09:00:00');
    $open = Ticket::factory()->forTenant($this->tenant)->create(['status' => TicketStatus::InProgress]);
    Event::fake([TicketLifecycleChanged::class, TicketStatusChanged::class]);

    $this->artisan('tickets:auto-close')->expectsOutputToContain('Closed 1 resolved tickets.')->assertSuccessful();

    $closed = ($this->fresh)($old);
    expect($closed->status)->toBe(TicketStatus::Closed)
        ->and($closed->closed_at?->toDateTimeString())->toBe('2026-09-21 03:10:00')
        ->and(($this->fresh)($recent)->status)->toBe(TicketStatus::Resolved)
        ->and(($this->fresh)($open)->status)->toBe(TicketStatus::InProgress);

    $event = $this->tenant->run(fn (): TicketEvent => TicketEvent::query()->where('ticket_id', $old->id)->sole());
    expect($event->getAttribute('type'))->toBe('status_changed')
        ->and($event->getAttribute('actor_type'))->toBe('system')
        ->and($event->getAttribute('actor_id'))->toBeNull()
        ->and($event->getAttribute('new_values')['status'])->toBe('closed');
    Event::assertDispatched(TicketLifecycleChanged::class, fn (TicketLifecycleChanged $e): bool => $e->ticketId === $old->id && $e->to === 'closed');
    Event::assertDispatched(TicketStatusChanged::class, fn (TicketStatusChanged $e): bool => $e->actorId === null && $e->to === 'closed');
});

it('uses each workspace its own period and can be limited to one workspace', function (): void {
    $patient = createTenant('patient', ['timezone' => 'UTC']);
    $patient->run(fn () => app(Settings::class)->update('tickets', ['auto_close_days' => 30]));
    $mine = ($this->resolved)($this->tenant, '2026-09-10 00:00:00');
    $theirs = ($this->resolved)($patient, '2026-09-10 00:00:00');

    $this->artisan('tickets:auto-close', ['--tenant' => 'patient'])->assertSuccessful();
    expect(($this->fresh)($mine)->status)->toBe(TicketStatus::Resolved);

    $this->artisan('tickets:auto-close')->assertSuccessful();
    expect(($this->fresh)($mine)->status)->toBe(TicketStatus::Closed)
        ->and(($this->fresh)($theirs)->status)->toBe(TicketStatus::Resolved);
});

it('runs twice without closing anything twice', function (): void {
    $ticket = ($this->resolved)($this->tenant, '2026-09-01 00:00:00');

    $this->artisan('tickets:auto-close')->assertSuccessful();
    $this->artisan('tickets:auto-close')->expectsOutputToContain('Closed 0 resolved tickets.')->assertSuccessful();

    expect($this->tenant->run(fn (): int => TicketEvent::query()->where('ticket_id', $ticket->id)->count()))->toBe(1);
});

it('is scheduled daily at 03:10 on one server without overlapping', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'tickets:auto-close'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('10 3 * * *')
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue();
});
