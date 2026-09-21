<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Integrations\Models\ApiClient;
use Database\Factories\Concerns\ForTenant;
use Laravel\Passport\Database\Factories\ClientFactory;

/**
 * Extends Passport's factory so `ApiClient::factory()` keeps its parent's return type.
 */
final class ApiClientFactory extends ClientFactory
{
    use ForTenant;

    protected $model = ApiClient::class;

    /**
     * @return class-string<ApiClient>
     */
    public function modelName(): string
    {
        return ApiClient::class;
    }

    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'secret' => 'test-secret-'.fake()->lexify('????????????????'),
            'redirect_uris' => [],
            'grant_types' => [ApiClient::GRANT],
            'scopes' => ['tickets:read', 'tickets:write'],
            'revoked' => false,
        ];
    }

    /**
     * @param  list<string>  $scopes
     */
    public function scopes(array $scopes): static
    {
        return $this->state(['scopes' => $scopes]);
    }

    public function revoked(): static
    {
        return $this->state(['revoked' => true, 'revoked_at' => now()]);
    }
}
