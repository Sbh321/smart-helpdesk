<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Reporting\Models\ReportTicketFact;
use App\Modules\Tickets\Models\Ticket;
use Database\Factories\Concerns\ForTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReportTicketFact> */
final class ReportTicketFactFactory extends Factory
{
    use ForTenant;

    protected $model = ReportTicketFact::class;

    public function definition(): array
    {
        return [
            'ticket_id' => fn (array $attributes): ?string => ($tenantId = $attributes['tenant_id'] ?? tenant()?->getTenantKey()) === null
                ? null : Ticket::factory()->state(['tenant_id' => $tenantId])->create()->id,
            'created_at' => '2026-09-21 09:00:00',
            'channel' => 'ui',
            'priority_level' => 'P3',
            'initial_priority_level' => 'P3',
            'refreshed_at' => '2026-09-21 09:00:00',
        ];
    }
}
