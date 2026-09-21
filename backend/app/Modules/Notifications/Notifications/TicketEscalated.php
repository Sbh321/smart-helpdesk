<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Notifications;

/** In-app and realtime, to the assignee and the managers. */
final class TicketEscalated extends TicketNotification
{
    public function kind(): string
    {
        return 'ticket_escalated';
    }

    protected function summary(): string
    {
        return 'The priority of a ticket was raised';
    }
}
