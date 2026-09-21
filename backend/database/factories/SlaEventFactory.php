<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Sla\Models\SlaEvent;
use App\Modules\Sla\Models\TicketSlaTimer;
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
            'ticket_id' => fn (array $attributes): ?string => TicketSlaTimer::query()
                ->whereKey($attributes['timer_id'] ?? null)->value('ticket_id'),
            'type' => 'started',
            'payload' => [],
            'created_at' => now(),
        ];
    }
}
