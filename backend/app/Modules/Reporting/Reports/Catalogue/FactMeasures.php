<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports\Catalogue;

use App\Modules\Reporting\Reports\Measure;

/**
 * Measures over `report_ticket_facts f` that several reports share, so a number means the same in each.
 */
final class FactMeasures
{
    /** A ticket was resolved at least once: resolved now, or reopened after a resolution. */
    public const string EVER_RESOLVED = 'f.resolved_at IS NOT NULL OR f.reopen_count > 0';

    public const string OPEN = 'f.resolved_at IS NULL AND f.closed_at IS NULL';

    public const string BREACHED = "f.first_response_sla = 'breached' OR f.resolution_sla = 'breached'";

    /** Met timers ÷ decided timers (met or breached), first response and resolution together, latest cycle. */
    public static function slaCompliance(): Measure
    {
        return new Measure('SLA compliance', <<<'SQL'
            round(100.0 * (count(*) FILTER (WHERE f.first_response_sla = 'met') + count(*) FILTER (WHERE f.resolution_sla = 'met'))
                / nullif(count(*) FILTER (WHERE f.first_response_sla IN ('met', 'breached')) + count(*) FILTER (WHERE f.resolution_sla IN ('met', 'breached')), 0), 1)
            SQL, 'percent');
    }

    /** Reopened tickets ÷ tickets resolved at least once. */
    public static function reopenRate(): Measure
    {
        return Measure::rate('Reopen rate', 'f.reopen_count > 0', self::EVER_RESOLVED);
    }
}
