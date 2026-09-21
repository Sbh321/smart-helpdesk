<?php

declare(strict_types=1);

namespace App\Modules\Media\Models;

use App\Modules\Contacts\Concerns\HasTags;
use App\Modules\Contacts\Models\Tag;
use App\Modules\Media\Domain\MediaTicketUse;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\MediaItemFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One stored file (docs/04-domain/media.md). `storage_key` is tenant-relative and never leaves the API.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $folder_id
 * @property string $name
 * @property string $storage_key
 * @property string $mime_type
 * @property int $size_bytes
 * @property int|null $width
 * @property int|null $height
 * @property string|null $checksum_sha256
 * @property array<string, mixed> $variants `{thumb: {key,width,height}, preview: {…}}` or `{variants_skipped: reason}`
 * @property string $source upload | email | api | system
 * @property string $state pending | ready | trashed | failed
 * @property string|null $uploaded_by_user_id
 * @property CarbonImmutable|null $trashed_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read int|null $links_count
 * @property-read MediaFolder|null $folder
 * @property-read Collection<int, Mediable> $links
 * @property-read Collection<int, Tag> $tags
 */
#[UseFactory(MediaItemFactory::class)]
final class MediaItem extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<MediaItemFactory> */
    use HasFactory;

    use HasTags;
    use HasUuids;

    /**
     * Ticket numbers this item is used in; filled per page by MediaItemResource::preload(), not a column.
     *
     * @var list<MediaTicketUse>|null
     */
    public ?array $ticketUses = null;

    protected $guarded = ['id', 'tenant_id'];

    /** @return BelongsTo<MediaFolder, $this> */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(MediaFolder::class, 'folder_id');
    }

    /** @return HasMany<Mediable, $this> */
    public function links(): HasMany
    {
        return $this->hasMany(Mediable::class, 'media_item_id');
    }

    protected function casts(): array
    {
        return [
            'variants' => 'array',
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'trashed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
