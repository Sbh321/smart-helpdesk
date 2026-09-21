<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Http\Resources;

use App\Modules\Media\Http\Resources\MediaItemResource;
use App\Modules\Tickets\Models\TicketComment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TicketComment */
final class TicketCommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ticket_id' => $this->ticket_id,
            'visibility' => $this->visibility,
            'author_type' => $this->author_type,
            'author_id' => $this->author_id,
            'body' => $this->body,
            'attachments' => MediaItemResource::collection($this->mediaLinks->pluck('item')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
