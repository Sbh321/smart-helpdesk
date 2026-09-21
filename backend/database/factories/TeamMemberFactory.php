<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Agents\Models\AgentProfile;
use App\Modules\Agents\Models\Team;
use App\Modules\Agents\Models\TeamMember;
use Database\Factories\Concerns\ForTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TeamMember> */
final class TeamMemberFactory extends Factory
{
    use ForTenant;

    protected $model = TeamMember::class;

    public function definition(): array
    {
        return [
            'team_id' => fn (array $attributes): ?string => ($tenantId = self::tenantOf($attributes)) === null
                ? null
                : Team::factory()->state(['tenant_id' => $tenantId])->create()->id,
            'agent_profile_id' => fn (array $attributes): ?string => ($tenantId = self::tenantOf($attributes)) === null
                ? null
                : AgentProfile::factory()->state(['tenant_id' => $tenantId])->create()->id,
            'joined_at' => now(),
        ];
    }

    /** @param array<string, mixed> $attributes */
    private static function tenantOf(array $attributes): ?string
    {
        $tenantId = $attributes['tenant_id'] ?? tenant()?->getTenantKey();

        return is_string($tenantId) ? $tenantId : null;
    }
}
