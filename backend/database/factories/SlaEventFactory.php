<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Sla\Models\SlaEvent;
use App\Modules\Sla\Models\TicketSlaTimer;
use App\Modules\Tenancy\Models\Tenant;
use Database\Factories\Concerns\ForTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SlaEvent> */
final class SlaEventFactory extends Factory
{
    use ForTenant;

    protected $model = SlaEvent::class;

    public function definition(): array
    {
        return [
            'timer_id' => fn (array $attributes): ?string => ($tenantId = $attributes['tenant_id'] ?? tenant()?->getTenantKey()) === null
                ? null : TicketSlaTimer::factory()->state(['tenant_id' => $tenantId])->create()->id,
            // Read inside the row's tenant: row-level security hides the timer anywhere else.
            'ticket_id' => fn (array $attributes): ?string => Tenant::query()->find($attributes['tenant_id'] ?? tenant()?->getTenantKey())
                ?->run(fn (): ?string => TicketSlaTimer::query()->whereKey($attributes['timer_id'] ?? null)->value('ticket_id')),
            'type' => 'started',
            'payload' => [],
            'created_at' => now(),
        ];
    }
}
