<?php

declare(strict_types=1);

namespace App\Modules\Media\Http\Controllers;

use App\Modules\Media\Actions\PurgeMedia;
use App\Modules\Media\Exceptions\MediaStateConflict;
use App\Modules\Media\Http\Requests\UpdateMediaRequest;
use App\Modules\Media\Http\Resources\MediaItemResource;
use App\Modules\Media\Models\MediaFolder;
use App\Modules\Media\Models\MediaItem;
use App\Support\Time\Clock;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

#[Group('Media')]
final class MediaManagementController
{
    /** Update a media item. */
    public function update(UpdateMediaRequest $request, MediaItem $media): MediaItemResource
    {
        $data = $request->validated();
        if (isset($data['folder_id'])) {
            MediaFolder::query()->findOrFail($data['folder_id']);
        }

        $media = DB::transaction(function () use ($media, $data, $request): MediaItem {
            $media = $this->lock($media, ['ready']);
            if (array_key_exists('folder_id', $data)) {
                $media->folder_id = $data['folder_id'];
            }
            $media->name = $request->sanitisedName() ?? $media->name;
            $media->save();
            if (isset($data['tags'])) {
                $media->syncTagNames(array_values($data['tags']));
            }

            return $media;
        });

        return new MediaItemResource($media);
    }

    /** Move a media item to the trash. */
    public function trash(MediaItem $media, Clock $clock): MediaItemResource
    {
        $media = DB::transaction(function () use ($media, $clock): MediaItem {
            $media = $this->lock($media, ['ready']);
            $media->forceFill(['state' => 'trashed', 'trashed_at' => $clock->now()])->save();

            return $media;
        });

        return new MediaItemResource($media);
    }

    /** Restore a media item from the trash. */
    public function restore(MediaItem $media): MediaItemResource
    {
        $media = DB::transaction(function () use ($media): MediaItem {
            $media = $this->lock($media, ['trashed']);
            $media->forceFill(['state' => 'ready', 'trashed_at' => null])->save();

            return $media;
        });

        return new MediaItemResource($media);
    }

    /** Delete a media item permanently. */
    public function destroy(MediaItem $media, PurgeMedia $purge): Response
    {
        $purge($media->id, 'trashed');

        return response()->noContent();
    }

    /** @param list<string> $expected */
    private function lock(MediaItem $media, array $expected): MediaItem
    {
        $media = MediaItem::query()->whereKey($media->id)->lockForUpdate()->firstOrFail();
        if (! in_array($media->state, $expected, true)) {
            throw MediaStateConflict::state($media->state, $expected);
        }

        return $media;
    }
}
