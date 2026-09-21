<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Integrations\Models\ApiClient;
use App\Modules\Integrations\Models\IdempotencyKey;
use Database\Factories\Concerns\ForTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<IdempotencyKey> */
final class IdempotencyKeyFactory extends Factory
{
    use ForTenant;

    protected $model = IdempotencyKey::class;

    public function definition(): array
    {
        return [
            'client_id' => function (array $attributes): ?string {
                $tenantId = $attributes['tenant_id'] ?? tenant()?->getTenantKey();

                return is_string($tenantId) ? (string) ApiClient::factory()->state(['tenant_id' => $tenantId])->create()->getKey() : null;
            },
            'key_hash' => hash('sha256', fake()->uuid()),
            'request_hash' => hash('sha256', fake()->sentence()),
            'route' => 'tickets.store',
            'response_status' => 201,
            'response_body' => ['data' => []],
            'created_at' => now(),
            'expires_at' => now()->addDay(),
        ];
    }
}
