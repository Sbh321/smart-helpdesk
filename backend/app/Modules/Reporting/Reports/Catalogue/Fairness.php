<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports\Catalogue;

use App\Modules\Reporting\Reports\Dimension;
use App\Modules\Reporting\Reports\Measure;
use App\Modules\Reporting\Reports\ReportParameters;
use App\Modules\Reporting\Reports\SqlReport;
use DateTimeInterface;

/**
 * RPT-A03 Fairness of the agents' load per day: Jain's index (Σx)² ÷ (n · Σx²) and the coefficient of
 * variation σ ÷ μ, the formulas of `FairnessIndex` (the assignment experiment's metric) written in SQL so
 * they run over the snapshots. x is each current agent's load at the end of the day (0 when the agent held
 * nothing); all-zero days count as perfectly fair (index 1, variation 0), as in `FairnessIndex`. A row
 * shows the average of its days; grouped by team, the agents are the team's current members.
 */
final class Fairness extends SqlReport
{
    public function key(): string
    {
        return 'rpt-a03';
    }

    public function title(): string
    {
        return 'Fairness';
    }

    public function description(): string
    {
        return "Jain's index and coefficient of variation of the agents' end-of-day load.";
    }

    public function group(): string
    {
        return 'agents';
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return ['agents.view'];
    }

    public function defaultDimension(): string
    {
        return 'day';
    }

    public function chart(): string
    {
        return 'line';
    }

    protected function source(): string
    {
        return $this->daily(false);
    }

    protected function sourceFor(ReportParameters $parameters): string
    {
        return $this->daily($parameters->dimension === 'team');
    }

    protected function sourceBindings(ReportParameters $parameters, DateTimeInterface $from, DateTimeInterface $to): array
    {
        return [$parameters->tenantId];
    }

    protected function tenantColumn(): string
    {
        return 'x.tenant_id';
    }

    protected function periodColumn(): string
    {
        return 'x.day_start';
    }

    public function dimensions(): array
    {
        return [
            'day' => new Dimension('Day', "to_char(x.day, 'YYYY-MM-DD')", isTime: true),
            'week' => new Dimension('Week', "to_char(date_trunc('week', x.day), 'YYYY-MM-DD')", isTime: true),
            'team' => new Dimension('Team', 'x.team_id', 'teams'),
        ];
    }

    public function measures(): array
    {
        return [
            'jain' => new Measure("Jain's index (1 = perfectly even)", 'round(avg(x.jain)::numeric, 4)', 'ratio'),
            'cv' => new Measure('Coefficient of variation', 'round(avg(x.cv)::numeric, 4)', 'ratio'),
            'agents' => new Measure('Agents (daily average)', 'round(avg(x.agents), 1)', 'number'),
            'average_load' => new Measure('Average load', 'round(100 * avg(x.mean_load)::numeric, 1)', 'percent'),
        ];
    }

    /** One row per day (and team): the day's fairness over the agents' loads. */
    private function daily(bool $byTeam): string
    {
        $team = $byTeam ? 'tm.team_id' : 'NULL::uuid';
        $join = $byTeam ? 'JOIN team_members tm ON tm.tenant_id = a.tenant_id AND tm.agent_profile_id = a.id' : '';

        return <<<SQL
            (
                SELECT d.tenant_id, d.day, (d.day::timestamp AT TIME ZONE {tz}) AS day_start, {$team} AS team_id,
                       count(*) AS agents, avg(l.load) AS mean_load,
                       coalesce(power(sum(l.load), 2) / nullif(count(*) * sum(l.load * l.load), 0), 1) AS jain,
                       coalesce(stddev_pop(l.load) / nullif(avg(l.load), 0), 0) AS cv
                FROM (SELECT DISTINCT tenant_id, day FROM report_daily_snapshots WHERE tenant_id = ? AND dimension = 'none') d
                JOIN agent_profiles a ON a.tenant_id = d.tenant_id
                {$join}
                CROSS JOIN LATERAL (
                    SELECT coalesce((SELECT (s.metrics ->> 'load')::float8 FROM report_daily_snapshots s
                                     WHERE s.tenant_id = d.tenant_id AND s.day = d.day AND s.dimension = 'agent'
                                       AND s.dimension_key = a.id::text), 0) AS load
                ) l
                GROUP BY d.tenant_id, d.day, 4
            ) x
            SQL;
    }
}
