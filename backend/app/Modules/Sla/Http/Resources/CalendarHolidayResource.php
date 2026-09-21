<?php

declare(strict_types=1);

namespace App\Modules\Sla\Http\Resources;

use App\Modules\Sla\Models\CalendarHoliday;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A non-working day of a business calendar.
 *
 * @mixin CalendarHoliday
 */
final class CalendarHolidayResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /** @format date */
            'date' => $this->date->format('Y-m-d'),
            'name' => $this->name,
            'recurs_yearly' => $this->recurs_yearly,
        ];
    }
}
