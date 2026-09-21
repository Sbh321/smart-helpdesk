<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Resources;

use App\Modules\Notifications\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Notification */
final class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->type,
            'ticket_id' => (string) ($this->data['ticket_id'] ?? ''),
            'ticket_number' => (int) ($this->data['ticket_number'] ?? 0),
            'ticket_title' => (string) ($this->data['ticket_title'] ?? ''),
            'summary' => (string) ($this->data['summary'] ?? ''),
            'read_at' => $this->read_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
