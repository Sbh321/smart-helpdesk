<?php

declare(strict_types=1);

use App\Modules\Integrations\Models\AccessToken;
use App\Modules\Tenancy\Enums\TenantStatus;

require_once __DIR__.'/IntegrationTestHelpers.php';

/*
 * POST /oauth/token, the client-credentials grant (docs/07-api/authentication.md §3).
 */

beforeEach(function (): void {
    $this->acme = createTenant('acme');
});

it('issues a one-hour bearer token for valid client credentials', function (): void {
    $client = createApiClient($this->acme);

    $response = requestToken($client, 'tickets:read')
        ->assertOk()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('expires_in', 3600);

    $token = AccessToken::query()->sole();

    expect($response->json('access_token'))->toBeString()->toContain('.')
        ->and($token->tenant_id)->toBe($this->acme->id)
        ->and($token->client_id)->toBe($client->id)
        ->and($token->user_id)->toBeNull()
        ->and($token->scopes)->toBe(['tickets:read']);
});

it('grants every scope of the client when none is requested', function (): void {
    $client = createApiClient($this->acme, ['tickets:read', 'contacts:read']);

    requestToken($client)->assertOk();

    expect(AccessToken::query()->sole()->scopes)->toBe(['tickets:read', 'contacts:read']);
});

it('rejects a wrong secret as invalid_client problem details', function (): void {
    $client = createApiClient($this->acme);

    requestToken($client, secret: 'not-the-secret')
        ->assertUnauthorized()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'invalid_client')
        ->assertJsonPath('error', 'invalid_client');

    expect(AccessToken::query()->count())->toBe(0);
});

it('rejects an unknown client id', function (): void {
    $this->post('/oauth/token', [
        'grant_type' => 'client_credentials',
        'client_id' => '019a0000-0000-7000-8000-000000000000',
        'client_secret' => TEST_CLIENT_SECRET,
    ], ['Accept' => 'application/json'])->assertUnauthorized()->assertJsonPath('code', 'invalid_client');
});

it('rejects a revoked client', function (): void {
    $client = createApiClient($this->acme, attributes: ['revoked' => true, 'revoked_at' => now()]);

    requestToken($client)->assertUnauthorized()->assertJsonPath('code', 'invalid_client');
});

it('rejects the clients of a suspended workspace', function (): void {
    $client = createApiClient($this->acme);
    $this->acme->forceFill(['status' => TenantStatus::Suspended])->save();

    requestToken($client)->assertUnauthorized()->assertJsonPath('code', 'invalid_client');
});

it('rejects an unknown scope', function (): void {
    $client = createApiClient($this->acme);

    requestToken($client, 'tickets:read admin:all')
        ->assertStatus(400)
        ->assertJsonPath('code', 'invalid_scope')
        ->assertJsonPath('error', 'invalid_scope');
});

it('rejects a scope the client was not granted instead of dropping it', function (): void {
    $client = createApiClient($this->acme, ['tickets:read']);

    requestToken($client, 'tickets:read tickets:write')
        ->assertStatus(400)
        ->assertJsonPath('code', 'invalid_scope');

    expect(AccessToken::query()->count())->toBe(0);
});

it('rejects the wildcard scope', function (): void {
    requestToken(createApiClient($this->acme), '*')->assertStatus(400)->assertJsonPath('code', 'invalid_scope');
});

it('supports only the client-credentials grant', function (string $grant): void {
    requestToken(createApiClient($this->acme), grant: $grant)
        ->assertStatus(400)
        ->assertJsonPath('code', 'unsupported_grant_type');
})->with(['password', 'authorization_code', 'refresh_token', 'implicit']);

it('limits token requests to ten a minute per client', function (): void {
    $client = createApiClient($this->acme);
    $other = createApiClient($this->acme);

    foreach (range(1, 10) as $attempt) {
        requestToken($client, secret: 'wrong')->assertUnauthorized();
    }

    requestToken($client)->assertStatus(429)->assertJsonPath('code', 'rate_limited');
    // The limit is per client, not per address.
    requestToken($other)->assertOk();
});
