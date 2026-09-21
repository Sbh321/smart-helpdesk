<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketComment;
use Database\Factories\Concerns\ForTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TicketComment> */
final class TicketCommentFactory extends Factory
{
    use ForTenant;

    protected $model = TicketComment::class;

    public function definition(): array
    {
        return [
            'ticket_id' => fn (array $attributes): ?string => ($tenantId = $attributes['tenant_id'] ?? tenant()?->getTenantKey()) === null
                ? null : Ticket::factory()->state(['tenant_id' => $tenantId])->create()->id,
            'visibility' => 'public',
            'author_type' => 'user',
            'author_id' => null,
            'body' => fake()->paragraph(),
            'edited_at' => null,
        ];
    }
}
