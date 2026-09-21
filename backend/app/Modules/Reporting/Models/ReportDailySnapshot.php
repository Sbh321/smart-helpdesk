<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\ReportDailySnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * End-of-day backlog and daily flows per dimension value, unique per `(tenant_id, day, dimension,
 * dimension_key)`; rows are upserted by `Support\DailySnapshotBuilder`, never saved through the model.
 *
 * @property string $id
 * @property string $tenant_id
 * @property CarbonImmutable $day
 * @property string $dimension
 * @property string $dimension_key
 * @property array<string, int|float> $metrics
 */
#[UseFactory(ReportDailySnapshotFactory::class)]
final class ReportDailySnapshot extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ReportDailySnapshotFactory> */
    use HasFactory;

    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['day' => 'immutable_date', 'metrics' => 'array', 'computed_at' => 'immutable_datetime'];
    }
}
