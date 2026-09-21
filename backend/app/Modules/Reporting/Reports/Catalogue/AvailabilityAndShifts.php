<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports\Catalogue;

use App\Modules\Reporting\Reports\Dimension;
use App\Modules\Reporting\Reports\Measure;
use App\Modules\Reporting\Reports\ReportParameters;
use App\Modules\Reporting\Reports\SqlReport;
use DateTimeInterface;

/**
 * RPT-A04 Availability and shifts, per agent and local day of the period: scheduled shift hours (a date
 * exception replaces the weekly rows of its day; a day off counts 0), the automatic assignments the agent
 * received, and how many of them fell outside the agent's shifts. The shift plan is the current one
 * (shift history is recorded by change capture but not replayed here), and "outside" is counted only
 * for agents that have a shift plan at all.
 */
final class AvailabilityAndShifts extends SqlReport
{
    /** Shift rows `x` that apply to agent `a` on local day `d.day`. */
    private const string APPLIES = <<<'SQL'
        x.tenant_id = a.tenant_id AND x.agent_profile_id = a.id
        AND (x.date = d.day OR (x.weekday = extract(dow FROM d.day)::int AND NOT EXISTS (
            SELECT 1 FROM agent_shifts y WHERE y.tenant_id = a.tenant_id AND y.agent_profile_id = a.id AND y.date = d.day)))
        SQL;

    public function key(): string
    {
        return 'rpt-a04';
    }

    public function title(): string
    {
        return 'Availability and shifts';
    }

    public function description(): string
    {
        return 'Scheduled shift hours and automatic assignments received outside shifts (should be 0).';
    }

    public function group(): string
    {
        return 'agents';
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return ['agents.view', 'tickets.view'];
    }

    public function defaultDimension(): string
    {
        return 'agent';
    }

    public function chart(): string
    {
        return 'table';
    }

    protected function source(): string
    {
        $applies = self::APPLIES;

        return <<<SQL
            (
                SELECT a.tenant_id, a.id AS agent_id, d.day, d.day_start,
                       (SELECT coalesce(sum(extract(epoch FROM (x.ends_at - x.starts_at))), 0) / 3600.0
                          FROM agent_shifts x WHERE {$applies} AND NOT x.is_off) AS shift_hours,
                       (SELECT count(*) FROM ticket_assignments ta
                         WHERE ta.tenant_id = a.tenant_id AND ta.agent_profile_id = a.id AND ta.reason = 'auto'
                           AND ta.created_at >= d.day_start AND ta.created_at < d.day_end) AS automatic,
                       (SELECT count(*) FROM ticket_assignments ta
                         WHERE ta.tenant_id = a.tenant_id AND ta.agent_profile_id = a.id AND ta.reason = 'auto'
                           AND ta.created_at >= d.day_start AND ta.created_at < d.day_end
                           AND EXISTS (SELECT 1 FROM agent_shifts z WHERE z.tenant_id = a.tenant_id AND z.agent_profile_id = a.id)
                           AND NOT EXISTS (SELECT 1 FROM agent_shifts x WHERE {$applies} AND NOT x.is_off
                                AND (ta.created_at AT TIME ZONE {tz})::time >= x.starts_at
                                AND (ta.created_at AT TIME ZONE {tz})::time < x.ends_at)) AS outside_shift
                FROM agent_profiles a
                CROSS JOIN (
                    SELECT g::date AS day, (g::date::timestamp AT TIME ZONE {tz}) AS day_start,
                           ((g::date + 1)::timestamp AT TIME ZONE {tz}) AS day_end
                    FROM generate_series((?::timestamptz AT TIME ZONE {tz})::date,
                                         ((?::timestamptz - interval '1 second') AT TIME ZONE {tz})::date, interval '1 day') g
                ) d
                WHERE a.tenant_id = ?
            ) s
            SQL;
    }

    protected function sourceBindings(ReportParameters $parameters, DateTimeInterface $from, DateTimeInterface $to): array
    {
        return [$from, $to, $parameters->tenantId];
    }

    protected function tenantColumn(): string
    {
        return 's.tenant_id';
    }

    protected function periodColumn(): string
    {
        return 's.day_start';
    }

    public function dimensions(): array
    {
        return [
            'agent' => new Dimension('Agent', 's.agent_id', 'agents'),
            'day' => new Dimension('Day', "to_char(s.day, 'YYYY-MM-DD')", isTime: true),
            'week' => new Dimension('Week', "to_char(date_trunc('week', s.day), 'YYYY-MM-DD')", isTime: true),
        ];
    }

    public function measures(): array
    {
        return [
            'shift_hours' => new Measure('Scheduled shift hours', 'round(sum(s.shift_hours), 1)', 'number'),
            'automatic' => new Measure('Automatic assignments received', 'coalesce(sum(s.automatic), 0)'),
            'outside_shift' => new Measure('Automatic assignments outside shifts', 'coalesce(sum(s.outside_shift), 0)'),
        ];
    }
}
