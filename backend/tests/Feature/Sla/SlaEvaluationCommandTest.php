<?php

declare(strict_types=1);

use App\Modules\Sla\Domain\Timer\TimerState;
use App\Modules\Sla\Events\SlaWarning;
use App\Modules\Sla\Jobs\EvaluateSlaTimers;
use App\Modules\Sla\Models\SlaEvent;
use App\Modules\Sla\Models\TicketSlaTimer;
use App\Modules\Tickets\Models\TicketEvent;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;

it('emits one warning and one breach despite repeated sweeps', function (): void {
    $clock = new FrozenClock('2026-09-19 09:45:00');
    $this->app->instance(Clock::class, $clock);
    $tenant = createTenant('sla-sweep');
    $timer = TicketSlaTimer::factory()->forTenant($tenant)->create([
        'started_at' => '2026-09-19 09:00:00',
        'warning_at' => '2026-09-19 09:45:00',
        'due_at' => '2026-09-19 10:00:00',
    ]);

    $this->artisan('sla:evaluate')->assertSuccessful();
    $this->artisan('sla:evaluate')->assertSuccessful();
    expect($timer->fresh()->state)->toBe(TimerState::Warning)
        ->and(SlaEvent::query()->withoutTenancy()->where('timer_id', $timer->id)->where('type', 'warning')->count())->toBe(1)
        ->and(TicketEvent::query()->withoutTenancy()->where('ticket_id', $timer->ticket_id)->where('type', 'sla_warning')->count())->toBe(1);

    $clock->set('2026-09-19 10:00:00');
    $this->artisan('sla:evaluate')->assertSuccessful();
    $this->artisan('sla:evaluate')->assertSuccessful();
    expect($timer->fresh()->state)->toBe(TimerState::Breached)
        ->and(SlaEvent::query()->withoutTenancy()->where('timer_id', $timer->id)->where('type', 'breached')->count())->toBe(1)
        ->and(TicketEvent::query()->withoutTenancy()->where('ticket_id', $timer->ticket_id)->where('type', 'sla_breached')->count())->toBe(1);
});

it('dispatches the sweep to the SLA queue served by Horizon', function (): void {
    Queue::fake();

    $this->artisan('sla:evaluate')->assertSuccessful();

    Queue::assertPushedOn('sla', EvaluateSlaTimers::class);
    expect(config('horizon.defaults.supervisor-1.queue'))->toContain('sla');
});

it('keeps sweeping the other tenants and writes the heartbeat when one tenant fails', function (): void {
    $clock = new FrozenClock('2026-09-19 09:45:00');
    $this->app->instance(Clock::class, $clock);
    Exceptions::fake();
    Cache::forget(EvaluateSlaTimers::HEARTBEAT_KEY);
    $due = ['started_at' => '2026-09-19 09:00:00', 'warning_at' => '2026-09-19 09:45:00', 'due_at' => '2026-09-19 10:00:00'];
    $broken = createTenant('sla-sweep-broken');
    $healthy = [createTenant('sla-sweep-a'), createTenant('sla-sweep-z')];
    $brokenTimer = TicketSlaTimer::factory()->forTenant($broken)->create($due);
    $healthyTimers = array_map(fn ($tenant) => TicketSlaTimer::factory()->forTenant($tenant)->create($due), $healthy);
    // A suspended tenant and a tenant without due work are not visited at all.
    $suspended = createTenant('sla-sweep-suspended', ['status' => 'suspended']);
    $suspendedTimer = TicketSlaTimer::factory()->forTenant($suspended)->create($due);

    // Fails inside the timer's transaction, so that timer rolls back and is retried next minute.
    TicketEvent::creating(function () use ($broken): void {
        if (tenant('id') === $broken->id) {
            throw new RuntimeException('History is broken for this tenant.');
        }
    });
    // SlaWarning is dispatched after the commit: a failing consumer is reported, the state stays.
    Event::listen(SlaWarning::class, function (SlaWarning $event) use ($healthyTimers): void {
        if ($event->timerId === $healthyTimers[0]->id) {
            throw new RuntimeException('A notification consumer failed.');
        }
    });

    $this->artisan('sla:evaluate')->assertSuccessful();

    expect($brokenTimer->fresh()->state)->toBe(TimerState::Running)
        ->and(SlaEvent::query()->withoutTenancy()->where('timer_id', $brokenTimer->id)->where('type', 'warning')->count())->toBe(0)
        ->and($healthyTimers[0]->fresh()->state)->toBe(TimerState::Warning)
        ->and($healthyTimers[1]->fresh()->state)->toBe(TimerState::Warning)
        ->and($suspendedTimer->fresh()->state)->toBe(TimerState::Running)
        ->and(Cache::get(EvaluateSlaTimers::HEARTBEAT_KEY))->toBe($clock->now()->timestamp)
        ->and(tenant())->toBeNull();
    Exceptions::assertReported(fn (RuntimeException $exception): bool => str_contains($exception->getMessage(), 'broken for this tenant'));
    Exceptions::assertReported(fn (RuntimeException $exception): bool => str_contains($exception->getMessage(), 'consumer failed'));
    Exceptions::assertReportedCount(2);
});

it('is a unique, single-attempt job that fits inside the worker timeout', function (): void {
    $job = new EvaluateSlaTimers;

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->tries)->toBe(1)
        ->and($job->timeout)->toBeLessThan((int) config('horizon.defaults.supervisor-1.timeout'))
        ->and($job->uniqueFor)->toBeGreaterThan($job->timeout)
        ->and($job->tags())->toContain('sla-sweep');
});

it('registers the sweep every minute with the scheduler flags of docs/11-operations/scheduler.md', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'sla:evaluate'));

    expect($events)->toHaveCount(1);
    $event = $events->first();
    expect($event->expression)->toBe('* * * * *')
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->runInBackground)->toBeTrue()
        ->and($event->evenWhenPaused)->toBeTrue();
});
