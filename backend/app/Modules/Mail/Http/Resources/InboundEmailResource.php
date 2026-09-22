<?php

declare(strict_types=1);

namespace App\Modules\Mail\Http\Resources;

use App\Modules\Mail\Models\InboundEmail;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the inbound log (Settings → Email): who wrote, to which address, and what became of it.
 *
 * @mixin InboundEmail
 */
final class InboundEmailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /**
             * @var 'comment'|'ticket'|'ignored'|'unrouted'|'rejected'
             *
             * What became of the message.
             */
            'state' => $this->state->value,
            /**
             * Why: `auto_reply`, `bounce`, `empty_reply`, `sender_not_allowed`, `tenant_mismatch`,
             * `unknown_sender`, `sender_archived`, `no_category`, `too_large`, `reopen_window_expired`,
             * `closed_as_duplicate`; null when nothing needs explaining.
             */
            'reason' => $this->reason,
            /**
             * @var 'plus_address'|'thread'|'intake'|null
             *
             * The rule that found the target.
             */
            'route' => $this->route,
            'from' => [
                'address' => $this->from_address,
                'name' => $this->from_name,
            ],
            'to' => $this->to_addresses,
            'cc' => $this->cc_addresses,
            'subject' => $this->subject,
            'message_id' => $this->message_id,
            'ticket' => $this->ticket === null ? null : [
                'id' => $this->ticket->id,
                'number' => $this->ticket->number,
                'title' => $this->ticket->title,
            ],
            'comment_id' => $this->comment_id,
            'contact_id' => $this->contact_id,
            /**
             * Files the message carried; `skipped` says why one was not stored (`type_not_allowed`,
             * `too_large`, `empty`, `quota_exceeded`, `storage_failed`, `limit`, `inline`, `not_stored`).
             */
            'attachments' => $this->attachments,
            'sent_at' => $this->sent_at?->toIso8601ZuluString(),
            'processed_at' => $this->processed_at->toIso8601ZuluString(),
        ];
    }
}
