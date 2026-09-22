<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports\Catalogue;

use App\Modules\Reporting\Reports\Dimension;
use App\Modules\Reporting\Reports\Filter;
use App\Modules\Reporting\Reports\Measure;
use App\Modules\Reporting\Reports\SqlReport;

/**
 * RPT-E01 Email channel: the inbound mail the workspace received in the period by outcome (comment,
 * ticket, ignored, rejected), reason, route or date (docs/04-domain/email.md §As built (M3-19)). Unrouted
 * mail names no workspace, so it is a platform row and never counted here. Outbound volume is not
 * recorded (the mailer keeps no send log), so the report covers the inbound side only.
 */
final class EmailChannel extends SqlReport
{
    public function key(): string
    {
        return 'rpt-e01';
    }

    public function title(): string
    {
        return 'Email channel';
    }

    public function description(): string
    {
        return 'Inbound email by outcome: replies added to tickets, tickets created, and mail ignored or rejected.';
    }

    public function group(): string
    {
        return 'channels';
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return ['mail.manage'];
    }

    public function defaultDimension(): string
    {
        return 'state';
    }

    protected function orderBy(): string
    {
        return 'count(*) DESC, 1';
    }

    protected function source(): string
    {
        return 'inbound_emails e';
    }

    protected function tenantColumn(): string
    {
        return 'e.tenant_id';
    }

    protected function periodColumn(): string
    {
        return 'e.processed_at';
    }

    public function dimensions(): array
    {
        return [
            'state' => new Dimension('Outcome', 'e.state', 'inbound_state'),
            'reason' => new Dimension('Reason', "coalesce(e.reason, '-')", 'inbound_reason'),
            'route' => new Dimension('Route', "coalesce(e.route, '-')", 'inbound_route'),
            'day' => Dimension::day('e.processed_at'),
            'month' => Dimension::month('e.processed_at'),
        ];
    }

    public function measures(): array
    {
        return [
            'messages' => Measure::count('Messages'),
            'comments' => Measure::count('Replies added', "e.state = 'comment'"),
            'tickets' => Measure::count('Tickets created', "e.state = 'ticket'"),
            'ignored' => Measure::count('Ignored', "e.state = 'ignored'"),
            'rejected' => Measure::count('Rejected', "e.state = 'rejected'"),
        ];
    }

    public function filters(): array
    {
        return [
            'state' => new Filter('Outcome', 'e.state', labels: 'inbound_state'),
        ];
    }
}
