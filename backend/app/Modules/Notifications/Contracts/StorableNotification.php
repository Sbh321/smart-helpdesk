<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Contracts;

use App\Models\User;

/**
 * A notification `Channels\TenantDatabaseChannel` can store: a kind (`notifications.type`), a dedupe
 * key per recipient and occurrence, and a payload of ids plus a summary.
 */
interface StorableNotification
{
    /** `ticket_assigned`, `export_ready`, … */
    public function kind(): string;

    /** `{kind}:{occurrence}`: a second delivery with the same key to the same user stores nothing. */
    public function key(): string;

    /** @return array<string, mixed> */
    public function toArray(User $notifiable): array;
}
