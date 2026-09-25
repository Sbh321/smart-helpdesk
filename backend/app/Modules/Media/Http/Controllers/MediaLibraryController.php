<?php

declare(strict_types=1);

namespace App\Modules\Media\Http\Controllers;

use App\Models\User;
use App\Modules\Media\Http\Requests\IndexMediaRequest;
use App\Modules\Media\Http\Resources\MediaItemResource;
use App\Modules\Media\Http\Resources\MediaUsageResource;
use App\Modules\Media\Models\MediaItem;
use App\Modules\Media\Support\MediaKeys;
use App\Modules\Media\Support\MediaQuota;
use App\Modules\Media\Support\MediaStorage;
use App\Modules\Media\Support\MediaUses;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Media')]
final class MediaLibraryController
{
    private const URL_TTL_SECONDS = 300;

    private const TYPE_GROUPS = [
        'image' => ['image/%'],
        'document' => ['application/pdf', 'application/vnd.openxmlformats-officedocument.%'],
        'text' => ['text/%'],
        'archive' => ['application/zip'],
    ];

    /** List media items. */
    #[QueryParameter('page', 'One-based page number.', type: 'integer')]
    #[QueryParameter('per_page', '1–100, default 25.', type: 'integer')]
    #[QueryParameter('sort', 'name, size_bytes or created_at, optionally prefixed by -.', type: 'string')]
    #[QueryParameter('search', 'Search media names.', type: 'string')]
    #[QueryParameter('filter[folder]', 'Folder ids, or `none`, comma separated.', type: 'string')]
    #[QueryParameter('filter[type]', 'image, document, text or archive, comma separated.', type: 'string')]
    #[QueryParameter('filter[tag]', 'Tag slugs, comma separated.', type: 'string')]
    #[QueryParameter('filter[uploader]', 'User ids, comma separated.', type: 'string')]
    #[QueryParameter('filter[trashed]', '`false` (default) or `true`.', type: 'string')]
    #[QueryParameter('filter[created_between]', '`YYYY-MM-DD,YYYY-MM-DD` in the workspace time zone, inclusive.', type: 'string')]
    public function index(IndexMediaRequest $request): AnonymousResourceCollection
    {
        $query = MediaItem::query()->where('state', $request->trashed() ? 'trashed' : 'ready');

        $folders = $request->folders();
        if ($folders !== []) {
            $query->where(function (Builder $query) use ($folders): void {
                $query->whereIn('folder_id', array_values(array_diff($folders, ['none'])));
                if (in_array('none', $folders, true)) {
                    $query->orWhereNull('folder_id');
                }
            });
        }

        $groups = $request->filterValues('type');
        if ($groups !== []) {
            $query->where(function (Builder $query) use ($groups): void {
                foreach ($groups as $group) {
                    foreach (self::TYPE_GROUPS[$group] as $pattern) {
                        $query->orWhere('mime_type', 'like', $pattern);
                    }
                }
            });
        }

        $tags = $request->filterValues('tag');
        if ($tags !== []) {
            $query->whereHas('tags', fn (Builder $tag) => $tag->whereIn('slug', $tags));
        }

        $uploaders = $request->filterValues('uploader');
        if ($uploaders !== []) {
            $query->whereIn('uploaded_by_user_id', $uploaders);
        }

        $range = $request->filterValues('created_between');
        if (count($range) === 2) {
            // Dates are in the workspace time zone, inclusive at both ends.
            $zone = (string) (tenant('timezone') ?? 'UTC');
            $query->where('created_at', '>=', CarbonImmutable::parse($range[0], $zone)->startOfDay()->utc())
                ->where('created_at', '<', CarbonImmutable::parse($range[1], $zone)->addDay()->startOfDay()->utc());
        }

        $search = $request->search();
        if ($search !== null) {
            $query->where('name', 'ilike', '%'.addcslashes($search, '%_\\').'%');
        }

        foreach ($request->sortColumns() as [$column, $direction]) {
            $query->orderBy($column, $direction);
        }

        $page = $query->orderBy('id')->paginate($request->perPage());
        MediaItemResource::preload($page->items());

        return MediaItemResource::collection($page);
    }

    /** Get a media item. */
    public function show(MediaItem $media): MediaItemResource
    {
        return new MediaItemResource($media);
    }

    /** Download a media item. */
    public function download(Request $request, MediaItem $media, MediaStorage $storage, MediaUses $uses): RedirectResponse
    {
        $this->authorizeRead($request, $media, $uses);

        return redirect()->away($storage->downloadUrl($media->storage_key, $media->name, self::URL_TTL_SECONDS));
    }

    /**
     * Open a media item in the browser.
     *
     * Redirects to a short-lived URL that shows the original in place: images, PDF and plain text
     * (CSV and logs as text). Any other type is sent as a download, like `GET /media/{media}/download`.
     */
    public function open(Request $request, MediaItem $media, MediaStorage $storage, MediaUses $uses): RedirectResponse
    {
        $this->authorizeRead($request, $media, $uses);

        return redirect()->away($storage->viewUrl($media->storage_key, $media->name, $media->mime_type, self::URL_TTL_SECONDS));
    }

    /** Download a variant of a media item. */
    public function variant(Request $request, MediaItem $media, string $name, MediaStorage $storage, MediaUses $uses): RedirectResponse
    {
        $this->authorizeRead($request, $media, $uses);
        $key = $media->variants[$name]['key'] ?? null;
        // Only a key this server generated for this item is ever signed.
        abort_unless(in_array($name, MediaKeys::VARIANTS, true) && $key === MediaKeys::variant($media->id, $name), 404);

        return redirect()->away($storage->variantUrl($key, self::URL_TTL_SECONDS));
    }

    /** Get storage usage. */
    public function usage(MediaQuota $quota): MediaUsageResource
    {
        return new MediaUsageResource($quota->usage((string) tenant()?->getTenantKey()));
    }

    private function authorizeRead(Request $request, MediaItem $media, MediaUses $uses): void
    {
        // Pending, failed and trashed items have no readable object for the library.
        abort_unless($media->state === 'ready', 404);

        $user = $request->user();
        // Someone else's generated file (a report export) does not exist for this reader, like
        // someone else's export at `GET /v1/exports/{export}`.
        abort_if($media->source === 'system' && $user instanceof User && $media->uploaded_by_user_id !== $user->id, 404);
        if (! $user instanceof User || ! $uses->canDownload($user, $media)) {
            throw new AuthorizationException('You cannot open a file that is only attached to records you cannot see.');
        }
    }
}
