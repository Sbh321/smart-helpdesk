<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Support;

/**
 * The derived rows of one ticket, ready to store or to compare with what is stored (`reports:verify`).
 */
final readonly class TicketReport
{
    /**
     * @param  list<array<string, mixed>>  $intervals  `report_ticket_intervals` rows without id and tenant
     * @param  array<string, mixed>  $facts  the `report_ticket_facts` row without id, tenant and refreshed_at
     */
    public function __construct(public array $intervals, public array $facts) {}
}
