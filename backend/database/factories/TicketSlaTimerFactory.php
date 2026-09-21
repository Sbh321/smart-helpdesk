<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Sla\Models\SlaPolicy;
use App\Modules\Sla\Models\TicketSlaTimer;
use App\Modules\Tickets\Models\Ticket;
use Database\Factories\Concerns\ForTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TicketSlaTimer> */
final class TicketSlaTimerFactory extends Factory
{
    use ForTenant;

    protected $model = TicketSlaTimer::class;

    public function definition(): array
    {
        $started = now()->toImmutable();

        return [
            'ticket_id' => fn (array $attributes): ?string => ($tenantId = $attributes['tenant_id'] ?? tenant()?->getTenantKey()) === null
                ? null : Ticket::factory()->state(['tenant_id' => $tenantId])->create()->id,
            'policy_id' => fn (array $attributes): ?string => ($tenantId = $attributes['tenant_id'] ?? tenant()?->getTenantKey()) === null
                ? null : SlaPolicy::factory()->state(['tenant_id' => $tenantId])->create()->id,
            'policy_version' => 1,
            'warning_fraction' => 0.75,
            'kind' => 'first_response',
            'cycle' => 1,
            'state' => 'running',
            'paused_from_state' => null,
            'target_minutes' => 60,
            'started_at' => $started,
            'warning_at' => $started->addMinutes(45),
            'due_at' => $started->addHour(),
            'paused_at' => null,
            'paused_total_seconds' => 0,
            'warned_at' => null,
            'breached_at' => null,
            'met_at' => null,
            'cancelled_at' => null,
            'calendar_id' => null,
            'strategy' => 'simple_sla_timer',
            'strategy_version' => '1.0.0',
        ];
    }
}
