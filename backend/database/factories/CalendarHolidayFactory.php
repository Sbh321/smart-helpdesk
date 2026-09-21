<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Sla\Models\BusinessCalendar;
use App\Modules\Sla\Models\CalendarHoliday;
use Database\Factories\Concerns\ForTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CalendarHoliday> */
final class CalendarHolidayFactory extends Factory
{
    use ForTenant;

    protected $model = CalendarHoliday::class;

    public function definition(): array
    {
        return [
            'calendar_id' => fn (array $attributes): ?string => ($tenantId = $attributes['tenant_id'] ?? tenant()?->getTenantKey()) === null
                ? null : BusinessCalendar::factory()->state(['tenant_id' => $tenantId])->create()->id,
            'date' => fake()->unique()->date(),
            'name' => fake()->words(2, true),
            'recurs_yearly' => false,
        ];
    }
}
