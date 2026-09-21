<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Sla\Models\SlaPolicy;
use App\Modules\Sla\Models\SlaTarget;
use Database\Factories\Concerns\ForTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SlaTarget> */
final class SlaTargetFactory extends Factory
{
    use ForTenant;

    protected $model = SlaTarget::class;

    public function definition(): array
    {
        return [
            'policy_id' => fn (array $attributes): ?string => ($tenantId = $attributes['tenant_id'] ?? tenant()?->getTenantKey()) === null
                ? null : SlaPolicy::factory()->state(['tenant_id' => $tenantId])->create()->id,
            'priority_level' => 'P3',
            'first_response_minutes' => 240,
            'resolution_minutes' => 1440,
        ];
    }
}
