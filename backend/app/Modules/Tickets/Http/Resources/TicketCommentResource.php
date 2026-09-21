<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Http\Resources;

use App\Modules\Media\Http\Resources\MediaItemResource;
use App\Modules\Tickets\Models\TicketComment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A comment on a ticket: a public reply (visible to the contact) or an internal note (agents with
 * `comments.internal` only; API clients never see internal notes).
 *
 * @mixin TicketComment
 */
final class TicketCommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ticket_id' => $this->ticket_id,
            /** @var 'public'|'internal' */
            'visibility' => $this->visibility,
            /**
             * @var 'user'|'contact'|'client'
             *
             * Who wrote it: a workspace user, the contact, or an API client.
             */
            'author_type' => $this->author_type,
            'author_id' => $this->author_id,
            /**
             * Plain text; line breaks are kept.
             *
             * @example I have reset the MFA device; please try again.
             */
            'body' => $this->body,
            'attachments' => MediaItemResource::collection($this->mediaLinks->pluck('item')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
