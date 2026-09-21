<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Reporting\Models\ReportDailySnapshot;
use Database\Factories\Concerns\ForTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReportDailySnapshot> */
final class ReportDailySnapshotFactory extends Factory
{
    use ForTenant;

    protected $model = ReportDailySnapshot::class;

    public function definition(): array
    {
        return [
            'day' => fake()->unique()->dateTimeBetween('-2 years', '-1 day')->format('Y-m-d'),
            'dimension' => 'none',
            'dimension_key' => '-',
            'metrics' => ['backlog' => 0],
            'computed_at' => '2026-09-21 00:05:00',
        ];
    }
}
