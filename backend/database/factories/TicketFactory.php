<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Contacts\Models\Contact;
use App\Modules\Tickets\Domain\Priority;
use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Models\Category;
use App\Modules\Tickets\Models\Ticket;
use Database\Factories\Concerns\ForTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Raw ticket rows for list and isolation tests. Numbers come from a per-factory sequence here;
 * real tickets get theirs from CreateTicket, which the number tests exercise.
 *
 * Pass a contact and category with `forContact()`, or let the factory create both in the tenant.
 *
 * @extends Factory<Ticket>
 */
final class TicketFactory extends Factory
{
    use ForTenant;

    protected $model = Ticket::class;

    private static int $number = 0;

    public function definition(): array
    {
        return [
            'number' => ++self::$number,
            'title' => fake()->sentence(6),
            'description' => fake()->paragraph(),
            'status' => TicketStatus::Open,
            'impact' => fake()->numberBetween(1, 4),
            'urgency' => fake()->numberBetween(1, 4),
            'priority_score' => fake()->randomFloat(2, 0, 100),
            'priority_level' => fake()->randomElement(Priority::cases()),
            'created_via' => 'seed',
            'resolved_at' => null,
            // Without an explicit contact and category, both are made in the ticket's own tenant.
            // Outside any tenant both stay null and the insert fails on tenant_id NOT NULL.
            'contact_id' => fn (array $attributes): ?string => ($tenantId = self::tenantOf($attributes)) === null
                ? null
                : Contact::factory()->state(['tenant_id' => $tenantId])->create()->id,
            'category_id' => fn (array $attributes): ?string => ($tenantId = self::tenantOf($attributes)) === null
                ? null
                : Category::factory()->state(['tenant_id' => $tenantId])->create()->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function tenantOf(array $attributes): ?string
    {
        $tenantId = $attributes['tenant_id'] ?? tenant()?->getTenantKey();

        return is_string($tenantId) ? $tenantId : null;
    }

    public function forContact(Contact $contact, Category $category): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $contact->tenant_id,
            'contact_id' => $contact->id,
            'organization_id' => $contact->organization_id,
            'category_id' => $category->id,
        ]);
    }

    public function status(TicketStatus $status): static
    {
        return $this->state(fn (): array => [
            'status' => $status,
            'resolved_at' => $status->isActive() ? null : now(),
        ]);
    }
}
