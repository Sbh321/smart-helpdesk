<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Console\Experiments;

use App\Modules\Reporting\Domain\History\ChangeReplayer;
use App\Modules\Reporting\Domain\Stats\Percentile;
use App\Modules\Reporting\Models\EntityChange;
use App\Modules\Reporting\Models\ReportTicketFact;
use App\Modules\Reporting\Models\ReportTicketInterval;
use App\Modules\Reporting\Support\DailySnapshotBuilder;
use App\Modules\Reporting\Support\HistorySubjects;
use App\Modules\Reporting\Support\TicketReportWriter;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tickets\Models\Ticket;
use App\Support\Experiments\Experiment;
use App\Support\Experiments\ExperimentContext;
use App\Support\Experiments\Metrics;
use App\Support\Experiments\SeededRandom;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Telescope\Telescope;
use RuntimeException;

/**
 * E6 — history and reporting correctness (docs/05-algorithms/history-and-time-analytics.md §9).
 *
 * Needs PostgreSQL: it migrates a *_test database from scratch (never the development database),
 * creates one workspace and replays generated ticket histories in time order the way the application
 * writes them — a ticket row change (captured by the change trigger), a ticket event, then the
 * incremental read-model refresh, with a daily snapshot at each workspace midnight. Then it measures:
 * (a) point-in-time reconstruction of tickets against recorded row versions, (b) incremental read
 * models against a rebuild from history, (c) latency of heavy report queries, (d) the write overhead
 * of the capture trigger. Modest size (300 tickets over 90 days) so it runs in about a minute.
 * Tables T10 and T11, plot 10.
 */
final class HistoryExperiment implements Experiment
{
    public const int TICKETS = 300;

    public const int DAYS = 90;

    public const int SAMPLES = 1000;

    public const int LATENCY_RUNS = 30;

    public const int OVERHEAD_UPDATES = 300;

    /** The frozen "now" at the end of the generated history. */
    private const string END = '2026-09-21 00:00:00';

    private const string TIMEZONE = 'Asia/Kathmandu';

    private FrozenClock $clock;

    public function key(): string
    {
        return 'e6';
    }

    public function title(): string
    {
        return 'History and reporting correctness';
    }

    public function run(ExperimentContext $context): array
    {
        if (class_exists(Telescope::class)) {
            Telescope::stopRecording();
        }
        $database = $this->useTestDatabase();
        $random = $context->random();
        $end = CarbonImmutable::parse(self::END, 'UTC');
        $this->clock = new FrozenClock($end);
        app()->instance(Clock::class, $this->clock);

        $tenant = new Tenant;
        $tenant->forceFill(['slug' => 'e6-history', 'name' => 'E6 history', 'status' => TenantStatus::Active, 'owner_email' => 'e6@example.test', 'timezone' => self::TIMEZONE])->save();

        $measured = $tenant->run(function () use ($tenant, $random, $end): array {
            $build = $this->buildHistory((string) $tenant->id, $random, $end);

            return [
                'build' => $build,
                'reconstruction' => $this->reconstruction($build['versions'], $random),
                'agreement' => $this->agreement((string) $tenant->id, $build['days']),
                'latency' => $this->latency((string) $tenant->id, $end, $build['ids']),
            ];
        });
        $overhead = $this->triggerOverhead($measured['build']['ids'], $random);

        $correctness = [...$measured['reconstruction'], ...$measured['agreement']];
        $context->output->csv('t10-history-correctness.csv', $correctness);
        $context->output->csv('t11-report-latency.csv', [...$measured['latency'], ...$overhead['rows']]);
        $context->output->json('summary.json', [
            'database' => $database,
            'tickets' => self::TICKETS,
            'ticket_steps' => $measured['build']['steps'],
            'entity_changes' => $measured['build']['entity_changes'],
            'days' => self::DAYS,
            'correctness' => $correctness,
            'latency' => $measured['latency'],
            'trigger_overhead' => $overhead['summary'],
        ]);

        return [
            'strategy' => ['name' => 'change_capture_replay', 'version' => '1.0.0', 'class' => ChangeReplayer::class],
            'settings' => [
                'tickets' => self::TICKETS,
                'days' => self::DAYS,
                'samples' => self::SAMPLES,
                'latency_runs' => self::LATENCY_RUNS,
                'overhead_updates' => self::OVERHEAD_UPDATES,
                'timezone' => self::TIMEZONE,
                'end' => self::END,
                'size_note' => 'Kept modest (300 tickets, 90 days) so the experiment runs in about a minute; the methodology names 10 000 tickets, which E5/M3-11 covers for latency. Latency and overhead are wall-clock timings on the machine named in the report and differ between runs.',
            ],
        ];
    }

    /**
     * Points the connections at the experiment database and migrates it from scratch.
     */
    private function useTestDatabase(): string
    {
        $database = (string) config('helpdesk.experiments.database');
        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException("E6 only runs against a *_test database, not [{$database}].");
        }

        config(['database.connections.pgsql.database' => $database, 'database.connections.pgsql_owner.database' => $database]);
        DB::purge('pgsql');
        DB::purge('pgsql_owner');
        Artisan::call('migrate:fresh', ['--database' => 'pgsql_owner', '--drop-views' => true, '--drop-types' => true, '--force' => true]);
        DB::purge('pgsql');

        return $database;
    }

    /**
     * Replays the plan in time order. Returns every ticket's recorded versions: the database time
     * just before and just after each statement and the row (as `to_jsonb`) after it.
     *
     * @return array{ids: list<string>, steps: int, entity_changes: int, days: list<CarbonImmutable>, versions: array<string, list<array{before: CarbonImmutable, after: CarbonImmutable, row: array<string, mixed>}>>}
     */
    private function buildHistory(string $tenantId, SeededRandom $random, CarbonImmutable $end): array
    {
        $now = $end->toIso8601ZuluString();
        $contact = (string) Str::uuid7();
        $category = (string) Str::uuid7();
        DB::table('contacts')->insert(['id' => $contact, 'tenant_id' => $tenantId, 'name' => 'E6 contact', 'email' => 'contact@example.test', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('categories')->insert(['id' => $category, 'tenant_id' => $tenantId, 'name' => 'E6 category', 'created_at' => $now, 'updated_at' => $now]);
        $teams = [];
        foreach (['Support', 'Billing', 'Network'] as $name) {
            $teams[] = $id = (string) Str::uuid7();
            DB::table('teams')->insert(['id' => $id, 'tenant_id' => $tenantId, 'name' => $name, 'created_at' => $now, 'updated_at' => $now]);
        }

        $steps = (new HistoryPlan)->steps($random, self::TICKETS, self::DAYS, $end, $teams);
        $writer = app(TicketReportWriter::class);
        $snapshots = app(DailySnapshotBuilder::class);
        $ids = [];
        $versions = [];
        $days = [];
        $nextDay = $steps[0]['at']->setTimezone(self::TIMEZONE)->startOfDay();

        foreach ($steps as $step) {
            // Workspace midnight passed: yesterday's snapshot is written, as reports:snapshot-daily does.
            while ($nextDay->addDay() <= $step['at']) {
                $this->clock->set($nextDay->addDay());
                $snapshots->write($tenantId, $nextDay, self::TIMEZONE, $this->clock->now());
                $days[] = $nextDay;
                $nextDay = $nextDay->addDay();
            }

            $this->clock->set($step['at']);
            $at = $step['at']->toIso8601ZuluString('microsecond');
            $before = $this->databaseNow();
            if ($step['type'] === 'created') {
                $ids[$step['ticket']] = $id = (string) Str::uuid7();
                DB::table('tickets')->insert([
                    'id' => $id, 'tenant_id' => $tenantId, 'number' => $step['ticket'] + 1,
                    'title' => "History ticket {$step['ticket']}", 'description' => 'Generated for experiment E6.',
                    'contact_id' => $contact, 'category_id' => $category, 'impact' => 2, 'urgency' => 2,
                    'created_via' => 'seed', 'created_at' => $at, 'updated_at' => $at, ...$step['set'],
                ]);
            } else {
                $id = $ids[$step['ticket']];
                DB::table('tickets')->where('id', $id)->update([...$step['set'], 'updated_at' => $at]);
            }
            $row = DB::selectOne('SELECT to_jsonb(t) AS row, clock_timestamp() AS at FROM tickets t WHERE t.id = ?', [$id]);
            $versions[$id][] = ['before' => $before, 'after' => CarbonImmutable::parse($row->at), 'row' => (array) json_decode($row->row, true)];

            DB::table('ticket_events')->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'ticket_id' => $id, 'type' => $step['type'],
                'actor_type' => 'system', 'old_values' => json_encode((object) $step['old']), 'new_values' => json_encode((object) $step['new']),
                'created_at' => $at,
            ]);
            // The incremental path: every ticket event refreshes that ticket's read models.
            $writer->refresh($id);
        }

        $this->clock->set($end);
        for ($yesterday = $end->setTimezone(self::TIMEZONE)->subDay()->startOfDay(); $nextDay <= $yesterday; $nextDay = $nextDay->addDay()) {
            $snapshots->write($tenantId, $nextDay, self::TIMEZONE, $end);
            $days[] = $nextDay;
        }

        return [
            'ids' => array_values($ids),
            'steps' => count($steps),
            'entity_changes' => EntityChange::query()->where('entity_type', 'tickets')->count(),
            'days' => $days,
            'versions' => $versions,
        ];
    }

    /**
     * (a) Random (ticket, instant) samples: the instant lies between two statements (or before the
     * first), so the expected state is known without the history — the recorded row after the
     * previous statement, or "did not exist". Backward replay (from the current row, as the history
     * API does) and forward replay (from the insert) must both return it.
     *
     * @param  array<string, list<array{before: CarbonImmutable, after: CarbonImmutable, row: array<string, mixed>}>>  $versions
     * @return list<array{check: string, items: int, correct: int, rate_pct: float}>
     */
    private function reconstruction(array $versions, SeededRandom $random): array
    {
        $replayer = new ChangeReplayer;
        $hidden = array_flip(HistorySubjects::hiddenColumns('tickets'));
        $ids = array_keys($versions);
        $changes = [];
        foreach (EntityChange::query()->where('entity_type', 'tickets')->orderBy('version')->get() as $change) {
            $changes[$change->entity_id][] = $change->toRecordedChange();
        }
        $currentRows = [];
        foreach (DB::select('SELECT id, to_jsonb(t) AS row FROM tickets t') as $row) {
            $currentRows[$row->id] = (array) json_decode($row->row, true);
        }

        $backward = $forward = 0;
        for ($n = 0; $n < self::SAMPLES; $n++) {
            $id = $random->pick($ids);
            $list = $versions[$id];
            $segment = $random->int(0, count($list));  // 0 = before the insert, k = after statement k
            $from = $segment === 0 ? $list[0]['before']->subSeconds(60) : $list[$segment - 1]['after'];
            $to = $segment === count($list) ? $list[$segment - 1]['after']->addSeconds(60) : $list[$segment]['before'];
            $span = max(0, (int) $from->diffInMicroseconds($to));
            $instant = $from->addMicroseconds($random->int(0, $span));
            $expected = $segment === 0 ? null : $list[$segment - 1]['row'];

            $backward += self::sameState($replayer->asOf($currentRows[$id], $changes[$id], $instant), $expected, $hidden) ? 1 : 0;
            $forward += self::sameState($replayer->forwardTo($changes[$id], $instant), $expected, $hidden) ? 1 : 0;
        }

        return [
            ['check' => 'reconstruction, backward replay from the current row', 'items' => self::SAMPLES, 'correct' => $backward, 'rate_pct' => Metrics::round(100 * $backward / self::SAMPLES, 2)],
            ['check' => 'reconstruction, forward replay from the insert', 'items' => self::SAMPLES, 'correct' => $forward, 'rate_pct' => Metrics::round(100 * $forward / self::SAMPLES, 2)],
        ];
    }

    /**
     * (b) The read models written incrementally (after each event, and each midnight) against a
     * fresh computation from history — the same functions `reports:rebuild` uses.
     *
     * @param  list<CarbonImmutable>  $days
     * @return list<array{check: string, items: int, correct: int, rate_pct: float}>
     */
    private function agreement(string $tenantId, array $days): array
    {
        $writer = app(TicketReportWriter::class);
        $snapshots = app(DailySnapshotBuilder::class);
        $tickets = Ticket::query()->get();
        $intervalsOk = $factsOk = 0;

        foreach ($tickets as $ticket) {
            $fresh = $writer->compute($ticket);
            $stored = ReportTicketInterval::query()->where('ticket_id', $ticket->id)->orderBy('seq')->get()
                ->map(fn (ReportTicketInterval $row): array => array_intersect_key($row->getAttributes(), $fresh->intervals[0] ?? []))->all();
            $intervalsOk += self::normalise($stored) === self::normalise($fresh->intervals) ? 1 : 0;
            $fact = ReportTicketFact::query()->where('ticket_id', $ticket->id)->first();
            $factsOk += $fact !== null && self::normalise(array_intersect_key($fact->getAttributes(), $fresh->facts)) === self::normalise($fresh->facts) ? 1 : 0;
        }

        $daysOk = 0;
        $key = fn (array $row): string => $row['dimension'].'|'.$row['dimension_key'];
        foreach ($days as $day) {
            $stored = collect(DB::table('report_daily_snapshots')->where('day', $day->toDateString())->get())
                ->map(fn (object $row): array => ['dimension' => $row->dimension, 'dimension_key' => $row->dimension_key, 'metrics' => json_decode((string) $row->metrics, true)])
                ->sortBy($key, SORT_STRING)->values()->all();
            $fresh = collect($snapshots->compute($tenantId, $day, self::TIMEZONE))->sortBy($key, SORT_STRING)->values()->all();
            $daysOk += self::normalise($stored) === self::normalise($fresh) ? 1 : 0;
        }

        $row = fn (string $check, int $items, int $correct): array => ['check' => $check, 'items' => $items, 'correct' => $correct, 'rate_pct' => Metrics::round(100 * Metrics::ratio($correct, $items), 2)];

        return [
            $row('ticket intervals, incremental vs rebuilt', $tickets->count(), $intervalsOk),
            $row('ticket facts, incremental vs rebuilt', $tickets->count(), $factsOk),
            $row('daily snapshots, incremental vs rebuilt', count($days), $daysOk),
        ];
    }

    /**
     * (c) Latency of the heaviest report queries on this data, each run LATENCY_RUNS times.
     *
     * @param  list<string>  $ids
     * @return list<array{measure: string, runs: int, p50_ms: float, p95_ms: float, max_ms: float}>
     */
    private function latency(string $tenantId, CarbonImmutable $end, array $ids): array
    {
        $from = $end->subDays(self::DAYS)->toDateString();
        $to = $end->toDateString();
        $queries = [
            'backlog by team per day, 90 days (daily snapshots)' => fn () => DB::select(
                "SELECT day, dimension_key, (metrics ->> 'backlog')::int AS backlog FROM report_daily_snapshots
                 WHERE tenant_id = ? AND dimension = 'team' AND day BETWEEN ? AND ? ORDER BY day, dimension_key",
                [$tenantId, $from, $to],
            ),
            'backlog per day, 90 days (from intervals, no snapshots)' => fn () => DB::select(
                "SELECT d::date AS day, count(i.ticket_id) AS backlog
                 FROM generate_series(?::date, ?::date, interval '1 day') d
                 LEFT JOIN report_ticket_intervals i ON i.tenant_id = ? AND i.starts_at < d + interval '1 day'
                  AND (i.ends_at IS NULL OR i.ends_at >= d + interval '1 day') AND i.status NOT IN ('resolved', 'closed')
                 GROUP BY 1 ORDER BY 1",
                [$from, $to, $tenantId],
            ),
            'time in status by team, 90 days (intervals)' => fn () => DB::select(
                'SELECT team_id, status, count(*) AS intervals, sum(wall_seconds) AS seconds,
                        percentile_cont(0.5) WITHIN GROUP (ORDER BY wall_seconds) AS median_s
                 FROM report_ticket_intervals WHERE tenant_id = ? AND ends_at >= ? AND wall_seconds IS NOT NULL
                 GROUP BY team_id, status ORDER BY team_id, status',
                [$tenantId, $from],
            ),
            'ticket as-of view (load changes and replay)' => function () use ($ids): void {
                $id = $ids[array_rand($ids)];
                $current = (array) json_decode((string) DB::selectOne('SELECT to_jsonb(t) AS row FROM tickets t WHERE t.id = ?', [$id])->row, true);
                $changes = EntityChange::query()->where('entity_type', 'tickets')->where('entity_id', $id)->orderBy('version')->get()
                    ->map(fn (EntityChange $change) => $change->toRecordedChange())->all();
                (new ChangeReplayer)->asOf($current, $changes, CarbonImmutable::parse(self::END)->subDays(30));
            },
        ];

        $rows = [];
        foreach ($queries as $name => $query) {
            $query(); // warm-up
            $rows[] = ['measure' => $name, ...self::timings(array_map(fn (): float => self::time($query), range(1, self::LATENCY_RUNS)))];
        }

        return $rows;
    }

    /**
     * (d) Ticket update latency with and without the capture trigger, on the owner connection inside
     * one transaction that is rolled back, so nothing is kept and the trigger is never left disabled.
     *
     * @param  list<string>  $ids
     * @return array{rows: list<array{measure: string, runs: int, p50_ms: float, p95_ms: float, max_ms: float}>, summary: array<string, float>}
     */
    private function triggerOverhead(array $ids, SeededRandom $random): array
    {
        $owner = DB::connection('pgsql_owner');
        $update = fn (int $n) => fn () => $owner->update(
            'UPDATE tickets SET title = ?, priority_score = ? WHERE id = ?',
            ["Overhead run {$n}", $n % 100, $random->pick($ids)],
        );

        $owner->beginTransaction();
        try {
            for ($n = 0; $n < 50; $n++) {
                $update($n)(); // warm-up
            }
            $with = array_map(fn (int $n): float => self::time($update($n)), range(1, self::OVERHEAD_UPDATES));
            $owner->statement('ALTER TABLE tickets DISABLE TRIGGER tickets_changes');
            $without = array_map(fn (int $n): float => self::time($update($n)), range(1, self::OVERHEAD_UPDATES));
        } finally {
            $owner->rollBack();
        }

        $withMean = Metrics::mean($with);
        $withoutMean = Metrics::mean($without);

        return [
            'rows' => [
                ['measure' => 'ticket update with change capture', ...self::timings($with)],
                ['measure' => 'ticket update without change capture', ...self::timings($without)],
            ],
            'summary' => [
                'mean_with_ms' => Metrics::round($withMean, 3),
                'mean_without_ms' => Metrics::round($withoutMean, 3),
                'overhead_pct' => Metrics::round(100 * ($withMean - $withoutMean) / $withoutMean, 1),
            ],
        ];
    }

    /**
     * Both absent, or the same attributes with the same values (strict), ignoring the columns capture never records.
     *
     * @param  array<string, mixed>|null  $actual
     * @param  array<string, mixed>|null  $expected
     * @param  array<string, int>  $hidden
     */
    private static function sameState(?array $actual, ?array $expected, array $hidden): bool
    {
        if ($actual === null || $expected === null) {
            return $actual === $expected;
        }
        $actual = array_diff_key($actual, $hidden);
        $expected = array_diff_key($expected, $hidden);
        ksort($actual);
        ksort($expected);

        return $actual === $expected;
    }

    private function databaseNow(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) DB::selectOne('SELECT clock_timestamp() AS at')->at);
    }

    /** Milliseconds one call takes. */
    private static function time(callable $call): float
    {
        $start = hrtime(true);
        $call();

        return (hrtime(true) - $start) / 1e6;
    }

    /**
     * @param  list<float>  $milliseconds
     * @return array{runs: int, p50_ms: float, p95_ms: float, max_ms: float}
     */
    private static function timings(array $milliseconds): array
    {
        return [
            'runs' => count($milliseconds),
            'p50_ms' => Metrics::round((float) Percentile::continuous($milliseconds, 0.5), 3),
            'p95_ms' => Metrics::round((float) Percentile::continuous($milliseconds, 0.95), 3),
            'max_ms' => Metrics::round(max($milliseconds), 3),
        ];
    }

    /** Same shape for stored and computed values (as `reports:verify` compares them). */
    private static function normalise(mixed $value): mixed
    {
        if (is_array($value)) {
            $normalised = array_map(self::normalise(...), $value);
            if (! array_is_list($normalised)) {
                ksort($normalised);
            }

            return $normalised;
        }

        return match (true) {
            $value instanceof DateTimeInterface => $value->getTimestamp(),
            is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/', $value) === 1 => CarbonImmutable::parse($value)->getTimestamp(),
            is_bool($value) => $value ? 1 : 0,
            is_numeric($value) => $value + 0,
            default => $value,
        };
    }
}
