<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Contacts\Enums\OrganizationTier;
use App\Modules\Contacts\Models\Organization;
use Database\Factories\Concerns\ForTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
final class OrganizationFactory extends Factory
{
    use ForTenant;

    protected $model = Organization::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'domain' => fake()->unique()->domainName(),
            'tier' => OrganizationTier::Standard,
            'external_ids' => [],
            'metadata' => [],
        ];
    }

    public function tier(OrganizationTier $tier): static
    {
        return $this->state(fn (): array => ['tier' => $tier]);
    }
}
