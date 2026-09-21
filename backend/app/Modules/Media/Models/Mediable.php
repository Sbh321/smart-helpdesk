<?php

declare(strict_types=1);

namespace App\Modules\Media\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\MediableFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Links a Media item to the record that uses it. `mediable_type` is a plain discriminator, not an
 * Eloquent morph: Media must not import the modules that own the subjects.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $media_item_id
 * @property string $mediable_type ticket | ticket_comment | tenant_branding | inbound_email
 * @property string $mediable_id
 * @property string $role attachment | logo | inline
 * @property CarbonImmutable $created_at
 * @property-read MediaItem $item
 */
#[UseFactory(MediableFactory::class)]
final class Mediable extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<MediableFactory> */
    use HasFactory;

    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['id', 'tenant_id'];

    /** @return BelongsTo<MediaItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class, 'media_item_id');
    }

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }
}
