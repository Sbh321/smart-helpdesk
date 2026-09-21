<?php

declare(strict_types=1);

namespace App\Modules\Sla\Http\Resources;

use App\Modules\Sla\Models\BusinessCalendar;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A Business calendar: weekly working hours and holidays that SLA timers count in.
 *
 * @mixin BusinessCalendar
 */
final class BusinessCalendarResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'timezone' => $this->timezone,
            /**
             * Working hours per weekday (`mon`–`sun`) as `[start, end]` pairs in the calendar's time zone.
             *
             * @example {"mon": [["09:00", "17:00"]], "sat": []}
             */
            'weekly_hours' => $this->weekly_hours,
            'is_default' => $this->is_default,
            'holidays' => CalendarHolidayResource::collection($this->holidays->sortBy('date')->values()),
        ];
    }
}
