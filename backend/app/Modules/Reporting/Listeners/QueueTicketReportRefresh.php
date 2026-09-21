<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Listeners;

use App\Modules\Reporting\Jobs\RefreshTicketReport;

/**
 * Any ticket domain event queues a refresh of that ticket's report rows, after the transaction commits
 * so the job reads what the event describes.
 */
final class QueueTicketReportRefresh
{
    public function handle(object $event): void
    {
        $ticketId = property_exists($event, 'ticketId') ? $event->ticketId : null;
        if (is_string($ticketId)) {
            RefreshTicketReport::dispatch($ticketId)->afterCommit();
        }
    }
}
