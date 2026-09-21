<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Sla\Models\BusinessCalendar;
use Database\Factories\Concerns\ForTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BusinessCalendar> */
final class BusinessCalendarFactory extends Factory
{
    use ForTenant;

    protected $model = BusinessCalendar::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'timezone' => 'Asia/Kathmandu',
            'weekly_hours' => ['sun' => [['09:00', '17:00']], 'mon' => [['09:00', '17:00']]],
            'is_default' => false,
        ];
    }
}
