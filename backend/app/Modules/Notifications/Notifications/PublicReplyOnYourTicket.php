<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Notifications;

/** In-app and realtime, to the assignee. */
final class PublicReplyOnYourTicket extends TicketNotification
{
    public function kind(): string
    {
        return 'public_reply';
    }

    protected function summary(): string
    {
        return 'New public reply on your ticket';
    }
}
