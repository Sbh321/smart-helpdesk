<?php

declare(strict_types=1);

use App\Modules\Sla\Jobs\EvaluateSlaTimers;
use App\Modules\Sla\Models\TicketSlaTimer;
use App\Modules\Tickets\Models\Ticket;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

/*
 * M2-03 acceptance: "10 000 timers checked in < 5 s locally" (experiment E4: one check over 10 000
 * running timers). Two readings are measured, fixture time excluded, and both print their time:
 *
 *  1. steady state: 10 000 running timers, of which one minute's worth is due. Must stay under 5 s.
 *  2. backlog: all 10 000 timers are due at once (the scheduler was down). Each one is locked, checked,
 *     updated and gets its SLA event and ticket history row, about 10 ms per timer with the
 *     change-capture triggers (docs/05-algorithms/sla-evaluation.md §As built). That does not fit in
 *     one job, so a sweep stops at its time budget and the next one continues. Asserted here: every
 *     sweep ends inside the job timeout, and a handful of sweeps clear the backlog exactly once.
 *
 * Not part of the default run:
 *
 *   vendor/bin/pest --testsuite=Performance --exclude-group=none
 */

/**
 * 10 000 running timers over five tenants (1 000 tickets x 2 kinds each), started 09:00, warning 09:45.
 * The first `$duePerTenant` tickets of a tenant have a first response due 09:59; everything else is due
 * at `$otherwiseDueAt`.
 */
function seedSlaSweepTimers(int $duePerTenant, string $warningAt, string $otherwiseDueAt): void
{
    for ($t = 1; $t <= 5; $t++) {
        $tenant = createTenant("sla-perf-{$t}");
        $seedTimer = TicketSlaTimer::factory()->forTenant($tenant)->create([
            'kind' => 'first_response', 'state' => 'running',
            'started_at' => '2026-09-19 09:00:00', 'warning_at' => '2026-09-19 09:45:00', 'due_at' => '2026-09-19 09:59:00',
        ]);
        $ticket = Ticket::query()->withoutTenancy()->findOrFail($seedTimer->ticket_id)->getAttributes();
        unset($ticket['search_vector']); // generated column
        $timer = $seedTimer->getAttributes();
        $later = ['warning_at' => $warningAt, 'due_at' => $otherwiseDueAt];

        $tickets = [];
        $timers = [[...$timer, ...$later, 'id' => (string) Str::uuid7(), 'kind' => 'resolution']];
        for ($n = 2; $n <= 1_000; $n++) {
            $ticketId = (string) Str::uuid7();
            $tickets[] = [...$ticket, 'id' => $ticketId, 'number' => 1_000_000 + $n];
            $timers[] = [...$timer, ...($n <= $duePerTenant ? [] : $later), 'id' => (string) Str::uuid7(), 'ticket_id' => $ticketId];
            $timers[] = [...$timer, ...$later, 'id' => (string) Str::uuid7(), 'ticket_id' => $ticketId, 'kind' => 'resolution'];
        }
        foreach (array_chunk($tickets, 500) as $chunk) {
            DB::table('tickets')->insert($chunk);
        }
        foreach (array_chunk($timers, 500) as $chunk) {
            DB::table('ticket_sla_timers')->insert($chunk);
        }
    }
}

function timedSlaSweep(string $label): float
{
    $started = hrtime(true);
    test()->artisan('sla:evaluate')->assertSuccessful();
    $elapsedSeconds = (hrtime(true) - $started) / 1_000_000_000;
    fwrite(STDERR, sprintf("\n  SLA sweep, %s: %.2f s\n", $label, $elapsedSeconds));

    return $elapsedSeconds;
}

it('checks ten thousand running timers, one minute of them due, in under five seconds', function (): void {
    $this->app->instance(Clock::class, new FrozenClock('2026-09-19 10:00:00'));
    seedSlaSweepTimers(duePerTenant: 20, warningAt: '2026-09-19 12:00:00', otherwiseDueAt: '2026-09-19 13:00:00');
    expect(DB::table('ticket_sla_timers')->where('state', 'running')->count())->toBe(10_000);

    $elapsedSeconds = timedSlaSweep('10 000 running, 100 due');

    expect(DB::table('ticket_sla_timers')->where('state', 'breached')->count())->toBe(100)
        ->and(DB::table('ticket_sla_timers')->where('state', 'running')->count())->toBe(9_900)
        ->and(DB::table('sla_events')->where('type', 'breached')->count())->toBe(100)
        ->and(DB::table('ticket_events')->where('type', 'sla_breached')->count())->toBe(100)
        ->and($elapsedSeconds)->toBeLessThan(5.0);
})->group('performance');

it('works off a backlog of ten thousand due timers over a few sweeps, each inside the job timeout', function (): void {
    $this->app->instance(Clock::class, new FrozenClock('2026-09-19 10:00:00'));
    // Every first response is past due (breach); every resolution is past its warning point only.
    seedSlaSweepTimers(duePerTenant: 1_000, warningAt: '2026-09-19 09:45:00', otherwiseDueAt: '2026-09-19 13:00:00');
    DB::table('ticket_sla_timers')->where('kind', 'first_response')->update(['due_at' => '2026-09-19 09:59:00']);
    expect(DB::table('ticket_sla_timers')->where('state', 'running')->count())->toBe(10_000);

    $sweeps = 0;
    do {
        $sweeps++;
        expect(timedSlaSweep("10 000 due, sweep {$sweeps}"))->toBeLessThan((float) (new EvaluateSlaTimers)->timeout);
        $left = DB::table('ticket_sla_timers')->where('state', 'running')->count();
    } while ($left > 0 && $sweeps < 6);

    expect($left)->toBe(0)
        ->and(DB::table('ticket_sla_timers')->where('state', 'breached')->count())->toBe(5_000)
        ->and(DB::table('ticket_sla_timers')->where('state', 'warning')->count())->toBe(5_000)
        ->and(DB::table('sla_events')->whereIn('type', ['warning', 'breached'])->count())->toBe(10_000)
        ->and(DB::table('ticket_events')->whereIn('type', ['sla_warning', 'sla_breached'])->count())->toBe(10_000);
})->group('performance');
