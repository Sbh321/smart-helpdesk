<?php

declare(strict_types=1);

namespace App\Modules\Sla\Models;

use App\Modules\Sla\Domain\Calendar\WorkingHoursCalendar;
use App\Modules\Sla\Domain\Timer\TimerState;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\BusinessCalendarFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $timezone
 * @property array<string, list<array{string, string}>> $weekly_hours
 * @property bool $is_default
 */
#[UseFactory(BusinessCalendarFactory::class)]
final class BusinessCalendar extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<BusinessCalendarFactory> */
    use HasFactory;

    use HasUuids;

    protected $guarded = ['id', 'tenant_id'];

    /** @return HasMany<CalendarHoliday, $this> */
    public function holidays(): HasMany
    {
        return $this->hasMany(CalendarHoliday::class, 'calendar_id');
    }

    /** @return HasMany<SlaPolicy, $this> */
    public function policies(): HasMany
    {
        return $this->hasMany(SlaPolicy::class, 'calendar_id');
    }

    /** Timers that still count against this calendar, so its hours and holidays must not move. */
    public function hasActiveTimers(): bool
    {
        return TicketSlaTimer::query()->where('calendar_id', $this->id)
            ->whereNotIn('state', [TimerState::Met->value, TimerState::Cancelled->value])->exists();
    }

    /**
     * Weekly hours in one canonical shape, so two definitions of the same working time compare equal
     * whatever the order of days, of windows or of JSON keys: days in week order, closed days left
     * out, windows sorted by opening time.
     *
     * @param  array<array-key, mixed>  $weeklyHours
     * @return array<string, list<array{string, string}>>
     */
    public static function normaliseWeeklyHours(array $weeklyHours): array
    {
        $normalised = [];
        foreach (WorkingHoursCalendar::DAYS as $day) {
            $windows = [];
            foreach (is_array($weeklyHours[$day] ?? null) ? $weeklyHours[$day] : [] as $window) {
                if (is_array($window)) {
                    $window = array_values($window);
                    $windows[] = [(string) ($window[0] ?? ''), (string) ($window[1] ?? '')];
                }
            }
            if ($windows !== []) {
                sort($windows);
                $normalised[$day] = $windows;
            }
        }

        return $normalised;
    }

    protected function casts(): array
    {
        return ['weekly_hours' => 'array', 'is_default' => 'boolean'];
    }
}
