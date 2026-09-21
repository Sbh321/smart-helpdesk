<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Agents\Models\AgentProfile;
use App\Modules\Agents\Models\AgentShift;
use Database\Factories\Concerns\ForTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AgentShift> */
final class AgentShiftFactory extends Factory
{
    use ForTenant;

    protected $model = AgentShift::class;

    public function definition(): array
    {
        return [
            'agent_profile_id' => fn (array $attributes): ?string => ($tenantId = self::tenantOf($attributes)) === null
                ? null
                : AgentProfile::factory()->state(['tenant_id' => $tenantId])->create()->id,
            'weekday' => fake()->numberBetween(0, 6),
            'date' => null,
            'starts_at' => '09:00:00',
            'ends_at' => '17:00:00',
            'is_off' => false,
        ];
    }

    /** @param array<string, mixed> $attributes */
    private static function tenantOf(array $attributes): ?string
    {
        $tenantId = $attributes['tenant_id'] ?? tenant()?->getTenantKey();

        return is_string($tenantId) ? $tenantId : null;
    }
}
