<?php

declare(strict_types=1);

namespace App\Modules\Media\Http\Resources;

use App\Modules\Media\Models\MediaItem;
use App\Modules\Media\Support\MediaUses;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MediaItem */
final class MediaItemResource extends JsonResource
{
    /**
     * Loads link counts, tags and ticket uses for a whole page in three queries. Call it before
     * `collection()`; a single resource falls back to it for its own item. It is a separate call and
     * not a `collection()` override, because an override hides the item shape from Scramble.
     *
     * @param  iterable<int, MediaItem>  $items
     */
    public static function preload(iterable $items): void
    {
        $missing = EloquentCollection::make($items)->filter(fn (MediaItem $item): bool => $item->ticketUses === null);
        if ($missing->isEmpty()) {
            return;
        }

        $missing->loadCount('links')->loadMissing('tags');
        $uses = app(MediaUses::class)->ticketsFor(
            (string) $missing->first()?->tenant_id,
            array_values($missing->modelKeys()),
        );
        foreach ($missing as $item) {
            $item->ticketUses = $uses[$item->id] ?? [];
        }
    }

    public function toArray(Request $request): array
    {
        /** @var MediaItem $item */
        $item = $this->resource;
        self::preload([$item]);

        return [
            'id' => $this->id,
            'folder_id' => $this->folder_id,
            'name' => $this->name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'width' => $this->width,
            'height' => $this->height,
            'checksum_sha256' => $this->checksum_sha256,
            'variants' => [
                'thumb' => $this->variantSize('thumb'),
                'preview' => $this->variantSize('preview'),
            ],
            /** Why no variants will appear: `pixel_limit` or `failed`; null while pending or present. */
            'variants_skipped' => is_string($this->variants['variants_skipped'] ?? null) ? $this->variants['variants_skipped'] : null,
            'source' => $this->source,
            'state' => $this->state,
            'uploaded_by_user_id' => $this->uploaded_by_user_id,
            'used_in_count' => (int) $this->links_count,
            'used_in_tickets' => MediaTicketUseResource::collection($item->ticketUses ?? []),
            'tags' => MediaTagResource::collection($this->tags),
            'trashed_at' => $this->trashed_at,
            'completed_at' => $this->completed_at,
            'created_at' => $this->created_at,
        ];
    }

    /** @return array{width: int, height: int}|null */
    private function variantSize(string $name): ?array
    {
        $variant = $this->variants[$name] ?? null;
        if (! is_array($variant) || ! isset($variant['width'], $variant['height'])) {
            return null;
        }

        return ['width' => (int) $variant['width'], 'height' => (int) $variant['height']];
    }
}
