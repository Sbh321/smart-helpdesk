<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Modules\Automation\Events\NoEligibleAgent;
use App\Modules\Notifications\Listeners\SendExportNotifications;
use App\Modules\Notifications\Listeners\SendTicketNotifications;
use App\Modules\Reporting\Events\ReportExportReady;
use App\Modules\Sla\Events\SlaBreached;
use App\Modules\Sla\Events\SlaWarning;
use App\Modules\Tickets\Events\CommentAdded;
use App\Modules\Tickets\Events\PriorityChanged;
use App\Modules\Tickets\Events\TicketAssigned;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;

/**
 * Listens to after-commit domain events and notifies users (docs/04-domain/notifications.md).
 * No domain module imports this one.
 */
final class NotificationsServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        Event::listen(TicketAssigned::class, [SendTicketNotifications::class, 'assigned']);
        Event::listen(CommentAdded::class, [SendTicketNotifications::class, 'commented']);
        Event::listen(SlaWarning::class, [SendTicketNotifications::class, 'slaWarning']);
        Event::listen(SlaBreached::class, [SendTicketNotifications::class, 'slaBreached']);
        Event::listen(PriorityChanged::class, [SendTicketNotifications::class, 'priorityChanged']);
        Event::listen(NoEligibleAgent::class, [SendTicketNotifications::class, 'unassignable']);
        Event::listen(ReportExportReady::class, [SendExportNotifications::class, 'ready']);
    }
}
