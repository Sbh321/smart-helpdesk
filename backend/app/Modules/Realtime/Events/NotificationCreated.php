<?php

declare(strict_types=1);

namespace App\Modules\Realtime\Events;

use App\Modules\Realtime\Support\Channels;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * `notification.created` on the recipient's own channel, for the bell (docs/04-domain/notifications.md).
 * The kind only; the bell refetches the unread count and the list.
 */
final readonly class NotificationCreated implements ShouldBroadcastNow
{
    public function __construct(
        public string $tenantId,
        public string $userId,
        public string $kind,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(Channels::user($this->tenantId, $this->userId))];
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    /** @return array{kind: string} */
    public function broadcastWith(): array
    {
        return ['kind' => $this->kind];
    }
}
