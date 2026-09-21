<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Agents\Models\Team;
use Database\Factories\Concerns\ForTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Team> */
final class TeamFactory extends Factory
{
    use ForTenant;

    protected $model = Team::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'description' => null,
        ];
    }
}
