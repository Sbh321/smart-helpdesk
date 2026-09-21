<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tickets\Models\TicketDuplicateSuggestion;
use Database\Factories\Concerns\ForTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TicketDuplicateSuggestion> */
final class TicketDuplicateSuggestionFactory extends Factory
{
    use ForTenant;

    protected $model = TicketDuplicateSuggestion::class;

    public function definition(): array
    {
        return [
            // Both tickets are made in the suggestion's own tenant. Outside any tenant they stay null
            // and the insert fails on the NOT NULL columns, as every tenant factory must.
            'ticket_id' => fn (array $attributes): ?string => self::ticketIn($attributes),
            'candidate_ticket_id' => fn (array $attributes): ?string => self::ticketIn($attributes),
            'score' => 0.5,
            'breakdown' => ['strategy' => 'jaccard_duplicates', 'strategy_version' => '1.0.0', 'shared_words' => ['login']],
            'decision' => 'pending',
            'decided_by_user_id' => null,
            'decided_at' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function ticketIn(array $attributes): ?string
    {
        $tenantId = $attributes['tenant_id'] ?? tenant()?->getTenantKey();

        if (! is_string($tenantId)) {
            return null;
        }

        return (string) TicketFactory::new()->forTenant(Tenant::query()->findOrFail($tenantId))->create()->getKey();
    }
}
