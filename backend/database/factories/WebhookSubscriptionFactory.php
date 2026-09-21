<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Integrations\Domain\Webhooks\WebhookSigner;
use App\Modules\Integrations\Models\WebhookSubscription;
use Database\Factories\Concerns\ForTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WebhookSubscription> */
final class WebhookSubscriptionFactory extends Factory
{
    use ForTenant;

    protected $model = WebhookSubscription::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'url' => 'https://hooks.example.com/'.fake()->lexify('????????'),
            'events' => ['ticket.created', 'ticket.status_changed'],
            'secret' => WebhookSigner::newSecret(),
            'api_version' => 'v1',
            'is_active' => true,
            'consecutive_failures' => 0,
        ];
    }

    /**
     * @param  list<string>  $events
     */
    public function events(array $events): static
    {
        return $this->state(['events' => $events]);
    }

    public function disabled(string $reason = WebhookSubscription::DISABLED_MANUALLY): static
    {
        return $this->state(['is_active' => false, 'disabled_at' => now(), 'disabled_reason' => $reason]);
    }
}
