<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Agents\Models\Skill;
use Database\Factories\Concerns\ForTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Skill> */
final class SkillFactory extends Factory
{
    use ForTenant;

    protected $model = Skill::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => null,
        ];
    }
}
