<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Channels;

use App\Models\User;
use App\Modules\Notifications\Contracts\StorableNotification;
use App\Modules\Notifications\Models\Notification as StoredNotification;
use App\Support\Time\Clock;
use Illuminate\Support\Str;

/**
 * The `database` channel of this application: tenant-scoped, UUID v7, time from `Clock`, and
 * idempotent. A second delivery with the same key for the same user (a retried job, an event fired
 * twice) inserts nothing.
 */
final readonly class TenantDatabaseChannel
{
    public function __construct(private Clock $clock) {}

    public function send(User $notifiable, StorableNotification $notification): void
    {
        $now = $this->clock->now();

        StoredNotification::query()->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $notifiable->tenant_id,
            'type' => $notification->kind(),
            'notifiable_type' => $notifiable->getMorphClass(),
            'notifiable_id' => $notifiable->id,
            'data' => json_encode($notification->toArray($notifiable), JSON_THROW_ON_ERROR),
            'notification_key' => $notification->key(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
