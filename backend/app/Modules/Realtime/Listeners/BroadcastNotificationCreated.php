<?php

declare(strict_types=1);

namespace App\Modules\Realtime\Listeners;

use App\Models\User;
use App\Modules\Notifications\Channels\TenantDatabaseChannel;
use App\Modules\Notifications\Contracts\StorableNotification;
use App\Modules\Realtime\Events\NotificationCreated;
use App\Modules\Realtime\Support\RealtimeSwitch;
use App\Modules\Realtime\Support\SafeBroadcast;
use Illuminate\Notifications\Events\NotificationSent;

/**
 * Rings the bell: once a notification is stored in the inbox, `notification.created` goes to the
 * recipient's channel. Runs inside the queued notification job (already in the workspace), so it
 * broadcasts directly.
 */
final readonly class BroadcastNotificationCreated
{
    public function __construct(private RealtimeSwitch $switch) {}

    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== TenantDatabaseChannel::class
            || ! $event->notifiable instanceof User
            || ! $event->notification instanceof StorableNotification
            || $event->notifiable->tenant_id !== tenant('id')
            || ! $this->switch->enabled()) {
            return;
        }

        SafeBroadcast::send(new NotificationCreated($event->notifiable->tenant_id, $event->notifiable->id, $event->notification->kind()));
    }
}
