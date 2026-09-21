<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Notifications;

/** In-app and realtime, to the assignee, or to the team when nobody is assigned. */
final class SlaWarningNotice extends TicketNotification
{
    public function kind(): string
    {
        return 'sla_warning';
    }

    protected function summary(): string
    {
        return 'A ticket is close to its SLA deadline';
    }
}
