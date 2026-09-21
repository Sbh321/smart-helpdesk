<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Modules\Agents\Enums\AgentAvailability;
use App\Modules\Agents\Models\AgentProfile;
use Database\Factories\Concerns\ForTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AgentProfile> */
final class AgentProfileFactory extends Factory
{
    use ForTenant;

    protected $model = AgentProfile::class;

    public function definition(): array
    {
        return [
            'user_id' => fn (array $attributes): ?string => ($tenantId = self::tenantOf($attributes)) === null
                ? null
                : User::factory()->state(['tenant_id' => $tenantId])->create()->id,
            'capacity' => 10,
            'availability' => AgentAvailability::Available,
            'active_ticket_count' => 0,
            'last_assigned_at' => null,
        ];
    }

    /** @param array<string, mixed> $attributes */
    private static function tenantOf(array $attributes): ?string
    {
        $tenantId = $attributes['tenant_id'] ?? tenant()?->getTenantKey();

        return is_string($tenantId) ? $tenantId : null;
    }
}
