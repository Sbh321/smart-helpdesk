<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Notifications;

/** In-app, mail and realtime, to the assignee and the managers. */
final class SlaBreachNotice extends TicketNotification
{
    public function kind(): string
    {
        return 'sla_breached';
    }

    protected function summary(): string
    {
        return 'A ticket has breached its SLA';
    }

    protected function mailed(): bool
    {
        return true;
    }
}
