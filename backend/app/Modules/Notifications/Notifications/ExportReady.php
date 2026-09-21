<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Notifications;

use App\Models\User;
use App\Modules\Notifications\Channels\TenantDatabaseChannel;
use App\Modules\Notifications\Contracts\StorableNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * "Export ready" (docs/04-domain/notifications.md): in-app and realtime, to the requester of the
 * export; not mailed, as the matrix says. The payload names the export and its Media item; the SPA
 * downloads through `GET /v1/media/{media_id}/download`, which signs a five-minute URL on each click.
 */
final class ExportReady extends Notification implements ShouldQueue, StorableNotification
{
    use Queueable;

    public function __construct(
        public readonly string $exportId,
        public readonly string $mediaId,
        public readonly string $fileName,
        public readonly int $rowCount,
    ) {
        $this->onQueue('notifications');
    }

    public function kind(): string
    {
        return 'export_ready';
    }

    public function key(): string
    {
        return 'export_ready:'.$this->exportId;
    }

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return [TenantDatabaseChannel::class, 'broadcast'];
    }

    /**
     * @return array{kind: string, export_id: string, media_id: string, file_name: string, row_count: int, summary: string}
     */
    public function toArray(User $notifiable): array
    {
        return [
            'kind' => $this->kind(),
            'export_id' => $this->exportId,
            'media_id' => $this->mediaId,
            'file_name' => $this->fileName,
            'row_count' => $this->rowCount,
            'summary' => 'Your export is ready',
        ];
    }

    public function toBroadcast(User $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    public function broadcastType(): string
    {
        return $this->kind();
    }
}
