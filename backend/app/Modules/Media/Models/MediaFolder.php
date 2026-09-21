<?php

declare(strict_types=1);

namespace App\Modules\Media\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\MediaFolderFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A library folder, at most five levels deep; `system_key` marks the protected Tickets, Email,
 * Branding and Reports folders (docs/04-domain/media.md).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $parent_id
 * @property string $name
 * @property string|null $system_key tickets | email | branding | reports
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read MediaFolder|null $parent
 * @property-read Collection<int, MediaFolder> $children
 * @property-read Collection<int, MediaItem> $items
 */
#[UseFactory(MediaFolderFactory::class)]
final class MediaFolder extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<MediaFolderFactory> */
    use HasFactory;

    use HasUuids;

    protected $guarded = ['id', 'tenant_id'];

    /** @return BelongsTo<MediaFolder, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<MediaFolder, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return HasMany<MediaItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(MediaItem::class, 'folder_id');
    }

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }
}
