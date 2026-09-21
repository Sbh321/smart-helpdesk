<?php

declare(strict_types=1);

namespace App\Modules\Sla\Listeners;

use App\Modules\Sla\Actions\StartTicketSla;
use App\Modules\Tickets\Events\TicketCreated;
use App\Modules\Tickets\Models\Ticket;

final readonly class StartSlaOnTicketCreated
{
    public function __construct(private StartTicketSla $start) {}

    public function handle(TicketCreated $event): void
    {
        ($this->start)(Ticket::query()->findOrFail($event->ticketId));
    }
}
