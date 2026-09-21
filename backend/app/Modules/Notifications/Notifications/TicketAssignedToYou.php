<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Notifications;

/** In-app, mail and realtime, to the agent. */
final class TicketAssignedToYou extends TicketNotification
{
    public function kind(): string
    {
        return 'ticket_assigned';
    }

    protected function summary(): string
    {
        return 'A ticket was assigned to you';
    }

    protected function mailed(): bool
    {
        return true;
    }
}
