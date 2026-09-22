<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports\Catalogue;

use App\Modules\Reporting\Reports\Dimension;
use App\Modules\Reporting\Reports\Measure;
use App\Modules\Reporting\Reports\ReportParameters;
use App\Modules\Reporting\Reports\SqlReport;
use DateTimeInterface;

/**
 * RPT-S03 Pause behaviour of the resolution timers started in the period: the share of their elapsed
 * wall-clock time spent paused (pending on the customer), and the tickets that were pending for more
 * than PENDING_HOURS in total (from the facts' closed pending intervals).
 */
final class PauseBehaviour extends SqlReport
{
    public const int PENDING_HOURS = 8;

    public function key(): string
    {
        return 'rpt-s03';
    }

    public function title(): string
    {
        return 'Pause behaviour';
    }

    public function description(): string
    {
        return 'Share of SLA time spent paused and tickets pending for more than '.self::PENDING_HOURS.' hours.';
    }

    public function group(): string
    {
        return 'sla';
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return ['tickets.view'];
    }

    public function defaultDimension(): string
    {
        return 'policy';
    }

    public function drillDownTo(): string
    {
        return 'tickets';
    }

    protected function recordKey(): string
    {
        return 'p.ticket_id';
    }

    protected function source(): string
    {
        return <<<'SQL'
            (
                SELECT tm.tenant_id, tm.ticket_id, tm.policy_id, tm.started_at, t.category_id,
                       coalesce(t.priority_override_level, t.priority_level) AS priority_level,
                       coalesce(f.pending_s, 0) AS pending_s,
                       tm.paused_total_seconds + CASE WHEN tm.paused_at IS NOT NULL
                           THEN greatest(0, extract(epoch FROM (?::timestamptz - tm.paused_at))) ELSE 0 END AS paused_s,
                       greatest(0, extract(epoch FROM (coalesce(tm.met_at, tm.cancelled_at, ?::timestamptz) - tm.started_at))) AS elapsed_s
                FROM ticket_sla_timers tm
                JOIN tickets t ON t.tenant_id = tm.tenant_id AND t.id = tm.ticket_id
                LEFT JOIN report_ticket_facts f ON f.tenant_id = tm.tenant_id AND f.ticket_id = tm.ticket_id
                WHERE tm.tenant_id = ? AND tm.kind = 'resolution'
            ) p
            SQL;
    }

    protected function sourceBindings(ReportParameters $parameters, DateTimeInterface $from, DateTimeInterface $to): array
    {
        return [$this->until($to), $this->until($to), $parameters->tenantId];
    }

    protected function tenantColumn(): string
    {
        return 'p.tenant_id';
    }

    protected function periodColumn(): string
    {
        return 'p.started_at';
    }

    public function dimensions(): array
    {
        return [
            'policy' => new Dimension('SLA policy', 'p.policy_id', 'sla_policies'),
            'category' => new Dimension('Category', 'p.category_id', 'categories'),
            'priority' => new Dimension('Priority', 'p.priority_level', 'priority'),
        ];
    }

    public function measures(): array
    {
        return [
            'timers' => Measure::count('Resolution timers'),
            'paused_timers' => Measure::count('Timers paused at least once', 'p.paused_s > 0'),
            'paused_share' => new Measure('Share of time paused', 'round(100.0 * sum(p.paused_s) / nullif(sum(p.elapsed_s), 0), 1)', 'percent'),
            'pending_long' => new Measure(
                'Tickets pending more than '.self::PENDING_HOURS.' h',
                'count(DISTINCT p.ticket_id) FILTER (WHERE p.pending_s > '.(self::PENDING_HOURS * 3600).')',
                condition: 'p.pending_s > '.(self::PENDING_HOURS * 3600),
            ),
        ];
    }
}
