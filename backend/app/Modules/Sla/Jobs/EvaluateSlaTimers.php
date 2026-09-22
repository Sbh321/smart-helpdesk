<?php

declare(strict_types=1);

namespace App\Modules\Sla\Jobs;

use App\Modules\Sla\Contracts\SlaStrategy;
use App\Modules\Sla\Domain\Timer\TimerState;
use App\Modules\Sla\Models\TicketSlaTimer;
use App\Modules\Sla\Support\SlaStrategyFactory;
use App\Modules\Sla\Support\SlaTimerStore;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Time\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Telescope\Telescope;
use Throwable;

/**
 * The minute sweep (docs/05-algorithms/sla-evaluation.md §Algorithm, docs/11-operations/scheduler.md).
 *
 * A central job: it picks the tenants that have due work, then evaluates each tenant inside its own
 * tenancy context. One failing timer or tenant is reported and skipped; the rest still run and the
 * heartbeat is still written. The next minute's sweep is the retry, so the job itself never retries.
 */
final class EvaluateSlaTimers implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const string HEARTBEAT_KEY = 'sla:last_sweep_at';

    public int $tries = 1;

    /** Below the Horizon supervisor timeout (60 s) and the queue `retry_after` (90 s). */
    public int $timeout = 55;

    /**
     * Wall-clock budget of one sweep. A backlog (the scheduler was down) costs about 10 ms per due
     * timer, so ten thousand would outlive the timeout and be killed mid-transaction. The sweep stops
     * taking new timers here instead; each timer commits on its own, and the next minute carries on.
     * Real elapsed time, not `Clock`: this bounds the worker, it is not domain time.
     */
    public const int BUDGET_SECONDS = 40;

    /** The unique lock frees itself if a worker dies mid-sweep. */
    public int $uniqueFor = 90;

    /**
     * @param  string|null  $tenantId  only this workspace, whatever its status, and no heartbeat: the demo
     *                                 replay (M3-13) sweeps its own workspace, suspended while it is built so
     *                                 the scheduler leaves it alone, under a frozen past clock; it must
     *                                 neither visit other workspaces nor tell /health that the scheduler ran
     */
    public function __construct(public readonly ?string $tenantId = null) {}

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ['sla-sweep', 'tenant:central'];
    }

    public function handle(Clock $clock, SlaStrategyFactory $strategies, SlaTimerStore $store): void
    {
        $now = $clock->now();
        $deadline = hrtime(true) + self::BUDGET_SECONDS * 1_000_000_000;
        $sweep = function () use ($now, $deadline, $strategies, $store): void {
            $failures = 0;

            // Row-level security shows a connection one workspace at a time, so the sweep visits every
            // active workspace rather than reading due tenant ids across all of them (M3-07). A
            // workspace with nothing due costs one query on the partial due indexes.
            $tenants = $this->tenantId === null ? Tenant::active() : Tenant::query()->whereKey($this->tenantId);
            foreach ($tenants->cursor() as $tenant) {
                if (hrtime(true) >= $deadline) {
                    Log::warning('SLA sweep used its time budget; the next sweep continues the backlog.');
                    break;
                }
                try {
                    $failures += $tenant->run(fn (): int => $this->sweepTenant($now, $deadline, $strategies, $store));
                } catch (Throwable $exception) {
                    $failures++;
                    report($exception);
                }
            }

            if ($failures > 0) {
                Log::warning('SLA sweep finished with failures.', ['failures' => $failures]);
            }
        };

        try {
            // A sweep can touch thousands of rows; Telescope records nothing for it and carries on
            // recording afterwards.
            class_exists(Telescope::class) ? Telescope::withoutRecording($sweep) : $sweep();
        } finally {
            if ($this->tenantId === null) {
                Cache::put(self::HEARTBEAT_KEY, $now->timestamp, 600);
            }
        }
    }

    /** @return int the number of timers that failed */
    private function sweepTenant(CarbonImmutable $now, int|float $deadline, SlaStrategyFactory $strategies, SlaTimerStore $store): int
    {
        $failures = 0;
        /** @var array<string, SlaStrategy> $built */
        $built = [];
        $dueIds = $this->due(TicketSlaTimer::query()->toBase(), $now)->orderBy('id')->pluck('id');

        foreach ($dueIds as $id) {
            if (hrtime(true) >= $deadline) {
                break;
            }
            try {
                DB::transaction(function () use ($id, $now, $strategies, $store, &$built): void {
                    $row = TicketSlaTimer::query()->whereKey($id)->lock('FOR UPDATE SKIP LOCKED')->first();
                    if ($row === null) {
                        return;
                    }
                    // One strategy instance per warning fraction, not one container build per timer.
                    $strategy = $built[(string) $row->warning_fraction]
                        ??= $strategies->forWarningFraction((float) $row->warning_fraction);
                    $outcome = $strategy->check($store->read($row), $now);
                    if ($outcome->events !== []) {
                        $store->store($row, $outcome, $row->paused_from_state);
                    }
                });
            } catch (Throwable $exception) {
                $failures++;
                report($exception);
            }
        }

        return $failures;
    }

    /** The due filter matches the two partial indexes `ticket_sla_timers_warning_pidx` and `…_due_pidx`. */
    private function due(QueryBuilder $query, CarbonImmutable $now): QueryBuilder
    {
        return $query->where(function (QueryBuilder $due) use ($now): void {
            $due->where(function (QueryBuilder $warnings) use ($now): void {
                $warnings->where('state', TimerState::Running->value)->where('warning_at', '<=', $now);
            })->orWhere(function (QueryBuilder $breaches) use ($now): void {
                $breaches->whereIn('state', [TimerState::Running->value, TimerState::Warning->value])
                    ->where('due_at', '<=', $now);
            });
        });
    }
}
