<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Notifications;

/** In-app and realtime, to the managers. */
final class TicketUnassignable extends TicketNotification
{
    public function kind(): string
    {
        return 'ticket_unassignable';
    }

    protected function summary(): string
    {
        return 'No agent is eligible for a ticket';
    }
}
