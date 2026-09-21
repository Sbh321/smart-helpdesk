<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Notifications;

/** In-app and realtime, to the assignee. */
final class InternalNoteOnYourTicket extends TicketNotification
{
    public function kind(): string
    {
        return 'internal_note';
    }

    protected function summary(): string
    {
        return 'New internal note on your ticket';
    }
}
