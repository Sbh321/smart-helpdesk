<?php

declare(strict_types=1);

namespace App\Modules\Sla\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\CalendarHolidayFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $calendar_id
 * @property CarbonImmutable $date
 * @property string $name
 * @property bool $recurs_yearly
 */
#[UseFactory(CalendarHolidayFactory::class)]
final class CalendarHoliday extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CalendarHolidayFactory> */
    use HasFactory;

    use HasUuids;

    protected $guarded = ['id', 'tenant_id'];

    /** @return BelongsTo<BusinessCalendar, $this> */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(BusinessCalendar::class, 'calendar_id');
    }

    protected function casts(): array
    {
        return ['date' => 'immutable_date', 'recurs_yearly' => 'boolean'];
    }
}
