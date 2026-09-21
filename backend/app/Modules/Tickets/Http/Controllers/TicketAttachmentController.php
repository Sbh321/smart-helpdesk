<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Http\Controllers;

use App\Modules\Media\Actions\AttachMedia;
use App\Modules\Media\Http\Resources\MediaItemResource;
use App\Modules\Media\Models\Mediable;
use App\Modules\Media\Models\MediaItem;
use App\Modules\Tickets\Http\Requests\StoreTicketAttachmentsRequest;
use App\Modules\Tickets\Models\Ticket;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

#[Group('Tickets')]
final class TicketAttachmentController
{
    public function index(Ticket $ticket): AnonymousResourceCollection
    {
        $items = MediaItem::query()
            ->whereHas('links', fn ($query) => $query
                ->where('mediable_type', 'ticket')->where('mediable_id', $ticket->id))
            ->orderByDesc('created_at')->get();
        MediaItemResource::preload($items);

        return MediaItemResource::collection($items);
    }

    public function store(StoreTicketAttachmentsRequest $request, Ticket $ticket, AttachMedia $attach): AnonymousResourceCollection
    {
        $data = $request->validated();
        DB::transaction(function () use ($data, $attach, $ticket): void {
            foreach ($data['media_ids'] as $mediaId) {
                $attach(MediaItem::query()->findOrFail($mediaId), 'ticket', $ticket->id);
            }
        });

        return $this->index($ticket);
    }

    public function destroy(Ticket $ticket, MediaItem $media): Response
    {
        $removed = Mediable::query()->where('media_item_id', $media->id)
            ->where('mediable_type', 'ticket')->where('mediable_id', $ticket->id)->delete();
        abort_if($removed === 0, 404);

        return response()->noContent();
    }
}
