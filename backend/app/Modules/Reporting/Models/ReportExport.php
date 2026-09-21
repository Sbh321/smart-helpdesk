<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Models;

use App\Modules\Media\Models\MediaItem;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\ReportExportFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One requested export of a catalogue report or of the filtered ticket list
 * (docs/08-database/entities.md §report_exports, docs/04-domain/reporting.md §Exports).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $report_key a catalogue id or `tickets-list`
 * @property array<string, mixed> $parameters
 * @property string $format csv | xlsx
 * @property string $state queued | running | ready | failed
 * @property string $requested_by_user_id
 * @property string|null $media_item_id
 * @property int|null $row_count
 * @property string|null $error too_large | quota_exceeded | forbidden | failed
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read MediaItem|null $mediaItem
 */
#[UseFactory(ReportExportFactory::class)]
final class ReportExport extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ReportExportFactory> */
    use HasFactory;

    use HasUuids;

    public const string TICKETS_LIST = 'tickets-list';

    public const array FORMATS = ['csv', 'xlsx'];

    protected $guarded = ['id', 'tenant_id'];

    /** @return BelongsTo<MediaItem, $this> */
    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class, 'media_item_id');
    }

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'row_count' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
