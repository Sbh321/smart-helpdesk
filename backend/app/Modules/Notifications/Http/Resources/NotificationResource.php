<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Resources;

use App\Modules\Notifications\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An in-app notification of the signed-in user (bell menu). Ticket notifications name the ticket;
 * `export_ready` names the export and its file; `inbound_email_rejected` the inbound log row (and
 * the ticket, when the message was aimed at one).
 *
 * @mixin Notification
 */
final class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /**
             * @var 'ticket_assigned'|'ticket_unassignable'|'ticket_escalated'|'public_reply'|'internal_note'|'sla_warning'|'sla_breached'|'export_ready'|'inbound_email_rejected'
             *
             * What happened.
             */
            'kind' => $this->type,
            // Ticket notifications name the ticket; `export_ready` names the export and its file instead.
            'ticket_id' => isset($this->data['ticket_id']) ? (string) $this->data['ticket_id'] : null,
            'ticket_number' => isset($this->data['ticket_number']) ? (int) $this->data['ticket_number'] : null,
            'ticket_title' => isset($this->data['ticket_title']) ? (string) $this->data['ticket_title'] : null,
            'export_id' => isset($this->data['export_id']) ? (string) $this->data['export_id'] : null,
            'media_id' => isset($this->data['media_id']) ? (string) $this->data['media_id'] : null,
            'file_name' => isset($this->data['file_name']) ? (string) $this->data['file_name'] : null,
            // `inbound_email_rejected` names the log row (Settings → Email); null for every other kind.
            'inbound_email_id' => isset($this->data['inbound_email_id']) ? (string) $this->data['inbound_email_id'] : null,
            /**
             * One line to show as is.
             *
             * @example Ticket #1042 was assigned to you
             */
            'summary' => (string) ($this->data['summary'] ?? ''),
            'read_at' => $this->read_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
