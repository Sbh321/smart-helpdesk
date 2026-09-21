<?php

declare(strict_types=1);

use App\Modules\Audit\Audit;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Integrations\Actions\RevokeApiClient;
use App\Modules\Integrations\Domain\ScopeMap;
use App\Modules\Reporting\Models\EntityChange;
use App\Modules\Tenancy\Support\TenantResolver;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketEvent;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/IntegrationTestHelpers.php';
require_once __DIR__.'/../Tickets/TicketTestHelpers.php';

/*
 * Bearer tokens of API clients on /v1 (docs/07-api/authentication.md §3–§4): tenant binding,
 * scope → permission mapping, route opt-in, revocation and the recorded actor.
 */

beforeEach(function (): void {
    $this->acme = createTenant('acme');
    $this->globex = createTenant('globex');
    [$this->contact, $this->category] = ticketPrerequisites($this->acme);
    // The assertions read acme's rows, which row-level security shows only inside acme.
    tenancy()->initialize($this->acme);
});

function clientTicketPayload(array $overrides = []): array
{
    return [
        'title' => 'Disk full on db-01',
        'description' => 'Monitoring reports 98 % usage on /var/lib/postgresql.',
        'contact_id' => test()->contact->id,
        'category_id' => test()->category->id,
        'impact' => 3,
        'urgency' => 4,
        ...$overrides,
    ];
}

it('creates a ticket from a token end to end with created_via api and the api_client actor', function (): void {
    $client = createApiClient($this->acme, ['tickets:write']);
    $token = issueToken($client, 'tickets:write');

    $response = $this->withToken($token)->postJson('/v1/tickets', clientTicketPayload())
        ->assertCreated()
        ->assertJsonPath('data.created_via', 'api');

    $ticket = Ticket::query()->withoutTenancy()->findOrFail($response->json('data.id'));
    $event = TicketEvent::query()->withoutTenancy()->where('ticket_id', $ticket->id)->where('type', 'created')->sole();
    $change = EntityChange::query()->withoutTenancy()
        ->where('entity_type', 'tickets')->where('entity_id', $ticket->id)->where('operation', 'insert')->sole();

    expect($ticket->tenant_id)->toBe($this->acme->id)
        ->and($ticket->created_via)->toBe('api')
        ->and($ticket->created_by_client_id)->toBe($client->id)
        ->and($ticket->created_by_user_id)->toBeNull()
        ->and($event->actor_type)->toBe('client')
        ->and($event->actor_id)->toBe($client->id)
        ->and($change->actor_type)->toBe('api_client')
        ->and($change->actor_id)->toBe($client->id);
});

it('lets tickets:read list and show tickets but not create them', function (): void {
    $client = createApiClient($this->acme, ['tickets:read']);
    $ticket = Ticket::factory()->forTenant($this->acme)->create();
    $this->withToken(issueToken($client));

    $this->getJson('/v1/tickets')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/v1/tickets/{$ticket->id}")->assertOk()->assertJsonPath('data.allowed_transitions', []);
    $this->postJson('/v1/tickets', clientTicketPayload())->assertForbidden()->assertJsonPath('code', 'forbidden');
});

it('limits a token to the scopes it was issued with', function (): void {
    $client = createApiClient($this->acme, ['tickets:read', 'tickets:write']);

    $this->withToken(issueToken($client, 'tickets:read'))
        ->postJson('/v1/tickets', clientTicketPayload())
        ->assertForbidden();
});

it('maps contact scopes to the contact routes', function (): void {
    $this->withToken(issueToken(createApiClient($this->acme, ['contacts:read'])));

    $this->getJson('/v1/contacts')->assertOk();
    $this->postJson('/v1/contacts', ['name' => 'Ops', 'email' => 'ops@acme.test'])->assertForbidden();
    $this->getJson('/v1/tickets')->assertForbidden();
});

it('never reaches user-level or administrative routes, whatever the scopes', function (string $method, string $path): void {
    $this->withToken(issueToken(createApiClient($this->acme, ScopeMap::scopes())));

    $this->json($method, $path)->assertForbidden()->assertJsonPath('code', 'forbidden');
})->with([
    'own profile' => ['get', '/v1/me'],
    'api clients' => ['get', '/v1/api-clients'],
    'create api clients' => ['post', '/v1/api-clients'],
    'users' => ['get', '/v1/users'],
    'roles' => ['get', '/v1/roles'],
    'settings' => ['get', '/v1/settings'],
    'notifications' => ['get', '/v1/notifications'],
]);

it('never grants assignment, internal notes, settings, users, roles or audit through the gate', function (string $permission): void {
    $client = createApiClient($this->acme, ScopeMap::scopes())->withTokenScopes(ScopeMap::scopes());

    expect($client->can($permission))->toBeFalse();
})->with(ScopeMap::NEVER);

it('rejects a token of workspace A on workspace B', function (): void {
    $token = issueToken(createApiClient($this->acme, ['tickets:read']));

    // A request that resolves workspace B (its session) while presenting A's token.
    $this->withSession([TenantResolver::SESSION_KEY => $this->globex->id])
        ->withHeader('Origin', 'https://'.config('helpdesk.hosts.app'))
        ->withToken($token)
        ->getJson('/v1/tickets')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'unauthenticated');
});

it('cannot see another workspace\'s records', function (): void {
    $foreign = Ticket::factory()->forTenant($this->globex)->create();
    $this->withToken(issueToken(createApiClient($this->acme, ['tickets:read'])));

    $this->getJson("/v1/tickets/{$foreign->id}")->assertNotFound();
    $this->getJson('/v1/tickets')->assertOk()->assertJsonCount(0, 'data');
});

it('rejects a revoked client on the very next request', function (): void {
    $client = createApiClient($this->acme, ['tickets:read']);
    $token = issueToken($client);

    $this->withToken($token)->getJson('/v1/tickets')->assertOk();

    $this->acme->run(fn () => app(RevokeApiClient::class)($client));

    $this->withToken($token)->getJson('/v1/tickets')->assertUnauthorized();
    requestToken($client)->assertUnauthorized()->assertJsonPath('code', 'invalid_client');
});

it('rejects a token whose client was revoked even if the token row survived', function (): void {
    $client = createApiClient($this->acme, ['tickets:read']);
    $token = issueToken($client);
    $client->forceFill(['revoked' => true])->save();

    $this->withToken($token)->getJson('/v1/tickets')->assertUnauthorized();
});

it('rejects a tampered token', function (): void {
    $token = issueToken(createApiClient($this->acme, ['tickets:read']));
    [$header, $payload, $signature] = explode('.', $token);

    $this->withToken($header.'.'.$payload.'.'.strrev($signature))->getJson('/v1/tickets')->assertUnauthorized();
});

it('writes the api_client actor into the audit log', function (): void {
    $client = createApiClient($this->acme, ['tickets:read']);
    $this->withToken(issueToken($client))->getJson('/v1/tickets')->assertOk();

    // Any audit entry written inside a client request carries the client.
    Route::middleware(['api', 'tenant'])->get('/v1/_test/audit', function () {
        Audit::record('test.client_action');

        return response()->json(['ok' => true]);
    })->middleware('api-clients');

    $this->withToken(issueToken($client))->getJson('/v1/_test/audit')->assertOk();

    $log = AuditLog::query()->where('action', 'test.client_action')->sole();

    expect($log->actor_type->value)->toBe('api_client')
        ->and($log->actor_id)->toBe($client->id)
        ->and($log->tenant_id)->toBe($this->acme->id);
});

it('limits API calls to 120 a minute per client', function (): void {
    $client = createApiClient($this->acme, ['tickets:read']);
    $other = createApiClient($this->acme, ['tickets:read']);
    $token = issueToken($client);
    $otherToken = issueToken($other);

    foreach (range(1, 120) as $call) {
        $this->withToken($token)->getJson('/v1/categories')->assertOk();
    }

    $this->withToken($token)->getJson('/v1/categories')->assertStatus(429)->assertJsonPath('code', 'rate_limited');
    $this->withToken($otherToken)->getJson('/v1/categories')->assertOk();
});

it('keeps the SPA session login working next to bearer tokens', function (): void {
    actingAsRole($this->acme, 'agent');

    $this->getJson('/v1/me')->assertOk();
    $this->getJson('/v1/tickets')->assertOk();
});
