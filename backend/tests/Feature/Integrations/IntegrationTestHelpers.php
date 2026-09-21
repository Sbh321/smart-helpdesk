<?php

declare(strict_types=1);

use App\Modules\Integrations\Models\ApiClient;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Testing\TestResponse;

const TEST_CLIENT_SECRET = 'a-known-secret-for-tests-only-0123456789';

/**
 * @param  list<string>  $scopes
 */
function createApiClient(Tenant $tenant, array $scopes = ['tickets:read', 'tickets:write'], array $attributes = []): ApiClient
{
    return ApiClient::factory()->forTenant($tenant)->scopes($scopes)->create(['secret' => TEST_CLIENT_SECRET, ...$attributes]);
}

function requestToken(ApiClient $client, ?string $scope = null, string $secret = TEST_CLIENT_SECRET, string $grant = 'client_credentials'): TestResponse
{
    return test()->post('/oauth/token', array_filter([
        'grant_type' => $grant,
        'client_id' => $client->id,
        'client_secret' => $secret,
        'scope' => $scope,
    ], fn (?string $value): bool => $value !== null), ['Accept' => 'application/json']);
}

function issueToken(ApiClient $client, ?string $scope = null): string
{
    $response = requestToken($client, $scope)->assertOk();

    return (string) $response->json('access_token');
}
