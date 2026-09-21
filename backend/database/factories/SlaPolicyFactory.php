<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Sla\Models\SlaPolicy;
use Database\Factories\Concerns\ForTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SlaPolicy> */
final class SlaPolicyFactory extends Factory
{
    use ForTenant;

    protected $model = SlaPolicy::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'is_default' => false,
            'applies_to_tier' => null,
            'warning_fraction' => 0.75,
            'calendar_id' => null,
            'version' => 1,
        ];
    }
}
