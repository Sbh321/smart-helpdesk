<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Tickets\Models\Category;
use Database\Factories\Concerns\ForTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
final class CategoryFactory extends Factory
{
    use ForTenant;

    protected $model = Category::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'default_team_id' => null,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
