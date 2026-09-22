<?php

declare(strict_types=1);

namespace App\Modules\Realtime;

use App\Modules\Realtime\Listeners\BroadcastNotificationCreated;
use App\Modules\Realtime\Listeners\BroadcastTicketActivity;
use App\Modules\Sla\Events\SlaBreached;
use App\Modules\Sla\Events\SlaWarning;
use App\Modules\Tickets\Events\CommentAdded;
use App\Modules\Tickets\Events\PriorityChanged;
use App\Modules\Tickets\Events\TicketAssigned;
use App\Modules\Tickets\Events\TicketCreated;
use App\Modules\Tickets\Events\TicketStatusChanged;
use App\Modules\Tickets\Events\TicketUpdated;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;

/**
 * Realtime (M3-16, ADR-0009, docs/03-architecture/realtime.md): channel authorisation and the
 * broadcasts that tell the SPA to refetch. Listeners only; no module imports this one.
 */
final class RealtimeServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        Event::listen(TicketCreated::class, [BroadcastTicketActivity::class, 'created']);
        Event::listen(TicketUpdated::class, [BroadcastTicketActivity::class, 'updated']);
        Event::listen(TicketAssigned::class, [BroadcastTicketActivity::class, 'assigned']);
        Event::listen(TicketStatusChanged::class, [BroadcastTicketActivity::class, 'statusChanged']);
        Event::listen(PriorityChanged::class, [BroadcastTicketActivity::class, 'priorityChanged']);
        Event::listen(SlaWarning::class, [BroadcastTicketActivity::class, 'slaChanged']);
        Event::listen(SlaBreached::class, [BroadcastTicketActivity::class, 'slaChanged']);
        Event::listen(CommentAdded::class, [BroadcastTicketActivity::class, 'commented']);
        Event::listen(NotificationSent::class, BroadcastNotificationCreated::class);
    }
}
