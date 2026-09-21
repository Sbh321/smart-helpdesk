<?php

declare(strict_types=1);

namespace App\Modules\Sla\Listeners;

use App\Modules\Sla\Actions\AdvanceTicketSla;
use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Events\TicketLifecycleChanged;
use App\Modules\Tickets\Models\Ticket;

final readonly class AdvanceSlaOnTicketLifecycle
{
    public function __construct(private AdvanceTicketSla $advance) {}

    public function handle(TicketLifecycleChanged $event): void
    {
        ($this->advance)(
            Ticket::query()->findOrFail($event->ticketId),
            TicketStatus::from($event->from),
            TicketStatus::from($event->to),
        );
    }
}
