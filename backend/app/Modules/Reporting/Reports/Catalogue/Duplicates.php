<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports\Catalogue;

use App\Modules\Reporting\Reports\Dimension;
use App\Modules\Reporting\Reports\Filter;
use App\Modules\Reporting\Reports\Measure;
use App\Modules\Reporting\Reports\SqlReport;

/**
 * RPT-T10 Duplicates: the duplicate suggestions made in the period and what agents decided. The
 * acceptance rate (accepted ÷ decided) is the detector's precision in production. A duplicate an agent
 * marked by hand is recorded as an accepted suggestion of the `manual` strategy.
 */
final class Duplicates extends SqlReport
{
    public function key(): string
    {
        return 'rpt-t10';
    }

    public function title(): string
    {
        return 'Duplicates';
    }

    public function description(): string
    {
        return 'Duplicate suggestions shown, accepted and dismissed, and tickets closed as duplicates.';
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
        return 'week';
    }

    public function chart(): string
    {
        return 'line';
    }

    public function drillDownTo(): string
    {
        return 'tickets';
    }

    protected function recordKey(): string
    {
        return 'd.ticket_id';
    }

    protected function source(): string
    {
        return 'ticket_duplicate_suggestions d JOIN tickets t ON t.tenant_id = d.tenant_id AND t.id = d.ticket_id';
    }

    protected function tenantColumn(): string
    {
        return 'd.tenant_id';
    }

    protected function periodColumn(): string
    {
        return 'd.created_at';
    }

    public function dimensions(): array
    {
        return [
            'day' => Dimension::day('d.created_at'),
            'week' => Dimension::week('d.created_at'),
            'category' => new Dimension('Category', 't.category_id', 'categories'),
            'strategy' => new Dimension('Strategy', "coalesce(d.breakdown ->> 'strategy', 'manual')"),
        ];
    }

    public function measures(): array
    {
        return [
            'suggestions' => Measure::count('Suggestions'),
            'accepted' => Measure::count('Accepted', "d.decision = 'accepted'"),
            'dismissed' => Measure::count('Dismissed', "d.decision = 'dismissed'"),
            'pending' => Measure::count('Not decided', "d.decision = 'pending'"),
            'acceptance_rate' => Measure::rate('Acceptance rate (precision)', "d.decision = 'accepted'", "d.decision IN ('accepted', 'dismissed')"),
            'closed_as_duplicate' => new Measure('Tickets closed as duplicate', 'count(DISTINCT d.ticket_id) FILTER (WHERE t.duplicate_of_id IS NOT NULL)'),
        ];
    }

    public function filters(): array
    {
        return [
            'category' => new Filter('Category', 't.category_id', labels: 'categories'),
            'decision' => new Filter('Decision', 'd.decision', labels: 'duplicate_decision'),
        ];
    }
}
