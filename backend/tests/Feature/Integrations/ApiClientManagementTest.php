<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Integrations\Models\AccessToken;
use App\Modules\Integrations\Models\ApiClient;
use Illuminate\Support\Facades\Hash;

require_once __DIR__.'/IntegrationTestHelpers.php';

/*
 * Settings → Developer → API clients: GET/POST /v1/api-clients, POST …/{client}/revoke
 * (docs/07-api/authentication.md §3), permission integrations.manage.
 */

beforeEach(function (): void {
    $this->acme = createTenant('acme');
    $this->globex = createTenant('globex');
});

it('creates a client and shows its secret exactly once', function (): void {
    $admin = actingAsRole($this->acme, 'admin');

    $response = $this->postJson('/v1/api-clients', ['name' => 'Monitoring', 'scopes' => ['tickets:write', 'contacts:read']])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Monitoring')
        ->assertJsonPath('data.scopes', ['tickets:write', 'contacts:read'])
        ->assertJsonPath('data.revoked', false);

    $id = $response->json('data.client_id');
    $secret = $response->json('data.client_secret');
    $client = ApiClient::query()->withoutGlobalScopes()->findOrFail($id);

    expect($response->json('data.id'))->toBe($id)
        ->and($secret)->toBeString()->toHaveLength(48)
        ->and($client->tenant_id)->toBe($this->acme->id)
        ->and($client->created_by_user_id)->toBe($admin->id)
        ->and($client->getAttributes()['secret'])->not->toBe($secret)
        ->and(Hash::check($secret, $client->getAttributes()['secret']))->toBeTrue();

    $this->getJson('/v1/api-clients')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonMissingPath('data.0.client_secret')
        ->assertJsonMissingPath('data.0.secret');

    $audit = AuditLog::query()->where('action', 'api_client.created')->sole();
    expect($audit->actor_id)->toBe($admin->id)
        ->and($audit->subject_id)->toBe($id)
        ->and($audit->changes['scopes'])->toBe(['tickets:write', 'contacts:read']);

    // The secret it showed works at the token endpoint.
    $this->flushSession();
    $this->post('/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $id, 'client_secret' => $secret], ['Accept' => 'application/json'])
        ->assertOk();
});

it('validates the name and the scopes', function (): void {
    actingAsRole($this->acme, 'admin');

    $this->postJson('/v1/api-clients', ['name' => '', 'scopes' => ['tickets:read', 'tickets:assign']])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonStructure(['errors' => ['name', 'scopes.1']]);

    $this->postJson('/v1/api-clients', ['name' => 'Empty', 'scopes' => []])->assertUnprocessable();
});

it('lists only the workspace\'s clients, active ones first', function (): void {
    actingAsRole($this->acme, 'admin');
    $revoked = createApiClient($this->acme, attributes: ['name' => 'Old', 'revoked' => true, 'revoked_at' => now()]);
    $active = createApiClient($this->acme, attributes: ['name' => 'New']);
    createApiClient($this->globex, attributes: ['name' => 'Foreign']);

    $this->getJson('/v1/api-clients')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $active->id)
        ->assertJsonPath('data.1.id', $revoked->id)
        ->assertJsonPath('data.1.revoked', true);
});

it('lists the scopes with their permissions', function (): void {
    actingAsRole($this->acme, 'developer');

    $this->getJson('/v1/api-clients/scopes')
        ->assertOk()
        ->assertJsonPath('data.0.scope', 'tickets:read')
        ->assertJsonPath('data.0.permissions', ['tickets.view']);
});

it('revokes a client and all its tokens, audited once', function (): void {
    $admin = actingAsRole($this->acme, 'admin');
    $client = createApiClient($this->acme);
    AccessToken::query()->create(['id' => str_repeat('a', 80), 'client_id' => $client->id, 'scopes' => [], 'revoked' => false]);

    $this->postJson("/v1/api-clients/{$client->id}/revoke")
        ->assertOk()
        ->assertJsonPath('data.revoked', true);
    $this->postJson("/v1/api-clients/{$client->id}/revoke")->assertOk();

    expect($client->fresh()->revoked)->toBeTrue()
        ->and($client->fresh()->revoked_at)->not->toBeNull()
        ->and(AccessToken::query()->where('revoked', false)->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'api_client.revoked')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'api_client.revoked')->sole()->actor_id)->toBe($admin->id);
});

it('does not reveal or revoke another workspace\'s client', function (): void {
    actingAsRole($this->acme, 'admin');
    $foreign = createApiClient($this->globex);

    $this->postJson("/v1/api-clients/{$foreign->id}/revoke")->assertNotFound();

    expect($foreign->fresh()->revoked)->toBeFalse();
});

it('requires integrations.manage', function (string $role, int $status): void {
    actingAsRole($this->acme, $role);

    $this->getJson('/v1/api-clients')->assertStatus($status);
    $this->postJson('/v1/api-clients', ['name' => 'X', 'scopes' => ['tickets:read']])->assertStatus($status === 200 ? 201 : $status);
})->with([
    'owner' => ['owner', 200],
    'admin' => ['admin', 200],
    'developer' => ['developer', 200],
    'manager' => ['manager', 403],
    'agent' => ['agent', 403],
]);
