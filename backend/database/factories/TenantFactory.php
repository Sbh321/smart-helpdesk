<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
final class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(100, 999),
            'name' => $name,
            'status' => TenantStatus::Active,
            'owner_email' => fake()->safeEmail(),
            'timezone' => 'Asia/Kathmandu',
        ];
    }

    public function slug(string $slug): static
    {
        return $this->state(fn (): array => ['slug' => $slug, 'name' => Str::headline($slug)]);
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => ['status' => TenantStatus::Suspended, 'suspended_at' => now()]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['status' => TenantStatus::Archived, 'archived_at' => now()]);
    }
}
