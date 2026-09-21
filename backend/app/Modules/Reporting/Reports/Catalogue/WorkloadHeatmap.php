<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports\Catalogue;

use App\Modules\Reporting\Reports\Dimension;
use App\Modules\Reporting\Reports\Measure;
use App\Modules\Reporting\Reports\ReportParameters;
use App\Modules\Reporting\Reports\SqlReport;
use DateTimeInterface;

/**
 * RPT-T11 Workload heatmap: tickets created and public replies sent by agents, by weekday and hour in the
 * workspace time zone. The `weekday_hour` key is `ISO weekday-hour`, for example `1-09` (Monday 09:00).
 */
final class WorkloadHeatmap extends SqlReport
{
    public function key(): string
    {
        return 'rpt-t11';
    }

    public function title(): string
    {
        return 'Workload heatmap';
    }

    public function description(): string
    {
        return 'Tickets created and replies sent by weekday and hour, in the workspace time zone.';
    }

    public function group(): string
    {
        return 'tickets';
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return ['tickets.view'];
    }

    public function defaultDimension(): string
    {
        return 'weekday_hour';
    }

    public function chart(): string
    {
        return 'heatmap';
    }

    protected function source(): string
    {
        return <<<'SQL'
            (
                SELECT tenant_id, created_at AS at, 'created' AS kind FROM tickets WHERE tenant_id = ?
                UNION ALL
                SELECT tenant_id, created_at, 'reply' FROM ticket_comments
                WHERE tenant_id = ? AND visibility = 'public' AND author_type = 'user'
            ) w
            SQL;
    }

    protected function sourceBindings(ReportParameters $parameters, DateTimeInterface $from, DateTimeInterface $to): array
    {
        return [$parameters->tenantId, $parameters->tenantId];
    }

    protected function tenantColumn(): string
    {
        return 'w.tenant_id';
    }

    protected function periodColumn(): string
    {
        return 'w.at';
    }

    public function dimensions(): array
    {
        return [
            'weekday_hour' => new Dimension('Weekday and hour', "to_char(w.at AT TIME ZONE {tz}, 'ID-HH24')", 'weekday_hour'),
            'weekday' => new Dimension('Weekday', "to_char(w.at AT TIME ZONE {tz}, 'ID')", 'weekday'),
            'hour' => new Dimension('Hour', "to_char(w.at AT TIME ZONE {tz}, 'HH24')"),
        ];
    }

    public function measures(): array
    {
        return [
            'created' => Measure::count('Tickets created', "w.kind = 'created'"),
            'replies' => Measure::count('Replies sent', "w.kind = 'reply'"),
            'total' => Measure::count('Created and replies'),
        ];
    }
}
