<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers;

use App\Modules\Notifications\Http\Resources\NotificationResource;
use App\Modules\Notifications\Models\Notification;
use App\Support\Time\Clock;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The signed-in user's own notifications. No permission: everybody may read their own inbox,
 * and nobody can read another user's.
 */
#[Group('Notifications')]
final class NotificationController
{
    public function __construct(private readonly Clock $clock) {}

    /** Unread first, then newest first. */
    #[QueryParameter('page', 'One-based page number.', type: 'integer')]
    #[QueryParameter('per_page', 'Page size, 1 to 100 (default 25).', type: 'integer')]
    #[QueryParameter('filter[unread]', '`true` lists unread notifications only.', type: 'string')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->mine($request)
            ->orderByRaw('read_at IS NOT NULL')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
        if ($request->input('filter.unread') === 'true') {
            $query->whereNull('read_at');
        }

        return NotificationResource::collection($query->paginate(min(100, max(1, $request->integer('per_page', 25)))));
    }

    /** Mark a notification read. */
    public function read(Request $request, string $notification): NotificationResource
    {
        $row = $this->mine($request)->whereKey($notification)->firstOrFail();
        if ($row->read_at === null) {
            $row->forceFill(['read_at' => $this->clock->now()])->save();
        }

        return new NotificationResource($row);
    }

    /** Mark every notification read. */
    public function readAll(Request $request): Response
    {
        $this->mine($request)->whereNull('read_at')->update(['read_at' => $this->clock->now()]);

        return response()->noContent();
    }

    /** @return Builder<Notification> */
    private function mine(Request $request): Builder
    {
        return Notification::query()->where('notifiable_id', $request->user()?->id);
    }
}
