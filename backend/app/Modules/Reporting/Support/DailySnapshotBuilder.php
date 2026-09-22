<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * End-of-day snapshots (docs/05-algorithms/history-and-time-analytics.md §5): for one workspace and day
 * (in the workspace time zone), per dimension value, the backlog at the end of the day and the day's
 * flows. Reads the interval and fact read models, `ticket_events` and `sla_events`; must run inside the
 * workspace. Writing replaces the day's rows, so it is idempotent.
 *
 * Metrics: `backlog` (tickets not resolved or closed at the end of the day), `created`, `resolved`,
 * `reopened`, `breached` (SLA timers), and for the agent dimension `load` = backlog / the agent's
 * current capacity (the assignment baseline's load; capacity history is not kept).
 * A flow is counted under the dimension value the ticket had at that instant; `category` uses the
 * ticket's current category because categories are not part of the interval state.
 */
final class DailySnapshotBuilder
{
    /** dimension => SQL of the key, over interval `i` and fact `f`. */
    private const array DIMENSIONS = [
        'none' => "'-'",
        'team' => "coalesce(i.team_id::text, '-')",
        'agent' => "coalesce(i.assigned_agent_id::text, '-')",
        'priority' => 'i.priority_level',
        'category' => "coalesce(f.category_id::text, '-')",
        'status' => 'i.status',
    ];

    private const array METRICS = ['backlog', 'created', 'resolved', 'reopened', 'breached'];

    /**
     * @return list<array{dimension: string, dimension_key: string, metrics: array<string, int|float>}>
     */
    public function compute(string $tenantId, CarbonImmutable $day, string $timezone): array
    {
        [$start, $end] = $this->bounds($day, $timezone);
        $rows = [];

        foreach (self::DIMENSIONS as $dimension => $key) {
            $metrics = [];
            $backlog = DB::select(<<<SQL
                SELECT {$key} AS k, count(*) AS n
                FROM report_ticket_intervals i
                JOIN report_ticket_facts f ON f.tenant_id = i.tenant_id AND f.ticket_id = i.ticket_id
                WHERE i.tenant_id = ? AND i.starts_at < ? AND (i.ends_at IS NULL OR i.ends_at >= ?)
                  AND i.status NOT IN ('resolved', 'closed')
                GROUP BY 1
                SQL, [$tenantId, $end, $end]);
            foreach ($backlog as $row) {
                $metrics[(string) $row->k]['backlog'] = (int) $row->n;
            }

            // MATERIALIZED: right after a rebuild the planner's statistics still describe empty read
            // models, and an inlined CTE was re-run once per fact row (about 50 ms a day for 300
            // tickets, 25 s for a 90-day demo rebuild). Computed once, the plan no longer depends on them.
            $flows = DB::select(<<<SQL
                WITH flows AS MATERIALIZED (
                    SELECT ticket_id, created_at AS at, 'created' AS kind FROM report_ticket_facts
                     WHERE tenant_id = ? AND created_at >= ? AND created_at < ?
                    UNION ALL
                    SELECT ticket_id, created_at, 'resolved' FROM ticket_events
                     WHERE tenant_id = ? AND created_at >= ? AND created_at < ?
                       AND type = 'status_changed' AND new_values ->> 'status' = 'resolved'
                    UNION ALL
                    SELECT ticket_id, created_at, 'reopened' FROM ticket_events
                     WHERE tenant_id = ? AND created_at >= ? AND created_at < ? AND type = 'reopened'
                    UNION ALL
                    SELECT ticket_id, created_at, 'breached' FROM sla_events
                     WHERE tenant_id = ? AND created_at >= ? AND created_at < ? AND type = 'breached'
                ), placed AS (
                    SELECT DISTINCT ON (fl.ticket_id, fl.at, fl.kind, fl.ctid_key) fl.kind, i.*, f.category_id AS f_category
                    FROM (SELECT flows.*, row_number() OVER () AS ctid_key FROM flows) fl
                    JOIN report_ticket_facts f ON f.tenant_id = ? AND f.ticket_id = fl.ticket_id
                    LEFT JOIN report_ticket_intervals i ON i.tenant_id = ? AND i.ticket_id = fl.ticket_id
                      AND i.starts_at <= fl.at AND (i.ends_at IS NULL OR fl.at < i.ends_at)
                    ORDER BY fl.ticket_id, fl.at, fl.kind, fl.ctid_key, i.seq DESC
                )
                SELECT {$this->placedKey($key)} AS k, kind, count(*) AS n FROM placed i GROUP BY 1, 2
                SQL, [
                $tenantId, $start, $end, $tenantId, $start, $end, $tenantId, $start, $end,
                $tenantId, $start, $end, $tenantId, $tenantId,
            ]);
            foreach ($flows as $row) {
                $metrics[(string) $row->k][(string) $row->kind] = (int) $row->n;
            }

            if ($dimension === 'agent') {
                $capacities = DB::table('agent_profiles')->where('tenant_id', $tenantId)->pluck('capacity', 'id');
                foreach ($metrics as $agentId => $values) {
                    $capacity = (int) ($capacities[$agentId] ?? 0);
                    if ($capacity > 0) {
                        $metrics[$agentId]['load'] = round(($values['backlog'] ?? 0) / $capacity, 4);
                    }
                }
            }

            ksort($metrics, SORT_STRING);
            foreach ($metrics as $dimensionKey => $values) {
                $complete = [];
                foreach (self::METRICS as $metric) {
                    $complete[$metric] = (int) ($values[$metric] ?? 0);
                }
                if (isset($values['load'])) {
                    $complete['load'] = $values['load'];
                }
                $rows[] = ['dimension' => $dimension, 'dimension_key' => (string) $dimensionKey, 'metrics' => $complete];
            }
        }

        return $rows;
    }

    /** Replaces the workspace's rows of the day. */
    public function write(string $tenantId, CarbonImmutable $day, string $timezone, CarbonImmutable $now): int
    {
        $rows = $this->compute($tenantId, $day, $timezone);

        DB::transaction(function () use ($tenantId, $day, $rows, $now): void {
            DB::table('report_daily_snapshots')->where('tenant_id', $tenantId)->where('day', $day->toDateString())->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('report_daily_snapshots')->insert(array_map(fn (array $row): array => [
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'day' => $day->toDateString(),
                    'dimension' => $row['dimension'],
                    'dimension_key' => $row['dimension_key'],
                    'metrics' => json_encode($row['metrics'], JSON_THROW_ON_ERROR),
                    'computed_at' => $now,
                ], $chunk));
            }
        });

        return count($rows);
    }

    /**
     * The day's start and end in UTC: local midnight to the next local midnight (23 or 25 hours on a
     * daylight-saving day).
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    public function bounds(CarbonImmutable $day, string $timezone): array
    {
        $start = CarbonImmutable::parse($day->toDateString(), $timezone)->startOfDay();

        return [$start->utc(), $start->addDay()->utc()];
    }

    /** The dimension key over the `placed` rows, where the fact's category is `f_category`. */
    private function placedKey(string $key): string
    {
        return str_replace('f.category_id', 'i.f_category', $key);
    }
}
