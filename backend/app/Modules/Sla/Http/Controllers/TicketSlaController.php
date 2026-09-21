<?php

declare(strict_types=1);

namespace App\Modules\Sla\Http\Controllers;

use App\Modules\Sla\Http\Resources\TicketSlaTimerResource;
use App\Modules\Sla\Models\TicketSlaTimer;
use App\Modules\Tickets\Models\Ticket;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('SLA')]
final class TicketSlaController
{
    public function show(Ticket $ticket): AnonymousResourceCollection
    {
        return TicketSlaTimerResource::collection(TicketSlaTimer::query()->where('ticket_id', $ticket->id)
            ->orderBy('kind')->orderBy('cycle')->get());
    }
}
