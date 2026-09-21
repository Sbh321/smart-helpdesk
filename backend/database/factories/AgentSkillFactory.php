<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Agents\Models\AgentProfile;
use App\Modules\Agents\Models\AgentSkill;
use App\Modules\Agents\Models\Skill;
use Database\Factories\Concerns\ForTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AgentSkill> */
final class AgentSkillFactory extends Factory
{
    use ForTenant;

    protected $model = AgentSkill::class;

    public function definition(): array
    {
        return [
            'agent_profile_id' => fn (array $attributes): ?string => ($tenantId = self::tenantOf($attributes)) === null
                ? null
                : AgentProfile::factory()->state(['tenant_id' => $tenantId])->create()->id,
            'skill_id' => fn (array $attributes): ?string => ($tenantId = self::tenantOf($attributes)) === null
                ? null
                : Skill::factory()->state(['tenant_id' => $tenantId])->create()->id,
            'level' => fake()->numberBetween(1, 5),
        ];
    }

    /** @param array<string, mixed> $attributes */
    private static function tenantOf(array $attributes): ?string
    {
        $tenantId = $attributes['tenant_id'] ?? tenant()?->getTenantKey();

        return is_string($tenantId) ? $tenantId : null;
    }
}
