<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Reporting\Models\ReportTicketInterval;
use App\Modules\Tickets\Models\Ticket;
use Database\Factories\Concerns\ForTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReportTicketInterval> */
final class ReportTicketIntervalFactory extends Factory
{
    use ForTenant;

    protected $model = ReportTicketInterval::class;

    public function definition(): array
    {
        return [
            // The ticket is made in the interval's own workspace; outside any workspace it stays null and
            // the insert fails on the NOT NULL column, as every tenant factory must.
            'ticket_id' => fn (array $attributes): ?string => ($tenantId = $attributes['tenant_id'] ?? tenant()?->getTenantKey()) === null
                ? null : Ticket::factory()->state(['tenant_id' => $tenantId])->create()->id,
            'seq' => 0,
            'status' => 'open',
            'assigned_agent_id' => null,
            'team_id' => null,
            'priority_level' => 'P3',
            'starts_at' => '2026-09-21 09:00:00',
            'ends_at' => null,
            'wall_seconds' => null,
            'business_seconds' => null,
        ];
    }
}
