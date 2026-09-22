<?php

declare(strict_types=1);

use App\Modules\Integrations\Domain\ScopeMap;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/RealtimeTestHelpers.php';
require_once __DIR__.'/../Integrations/IntegrationTestHelpers.php';

/*
 * POST /v1/broadcasting/auth (docs/03-architecture/realtime.md §Authorisation): the channel's tenant
 * must be the session's, the user needs the channel's permission, and internal notes have their own
 * channel behind comments.internal.
 */

beforeEach(function (): void {
    useReverbBroadcaster();
    $this->acme = createTenant('acme');
    $this->globex = createTenant('globex');
    $this->ticket = Ticket::factory()->forTenant($this->acme)->create();
    $this->globexTicket = Ticket::factory()->forTenant($this->globex)->create();
});

function authorizeChannel(string $channel): TestResponse
{
    return test()->postJson('/v1/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-'.$channel]);
}

it('signs the workspace, ticket, internal and own user channels for an agent', function (): void {
    $agent = actingAsRole($this->acme, 'agent');
    $a = $this->acme->id;

    foreach ([
        "tenants.{$a}.tickets",
        "tenants.{$a}.tickets.{$this->ticket->id}",
        "tenants.{$a}.tickets.{$this->ticket->id}.internal",
        "tenants.{$a}.users.{$agent->id}",
    ] as $channel) {
        $signature = hash_hmac('sha256', "1234.5678:private-{$channel}", 'test-secret');

        authorizeChannel($channel)->assertOk()->assertExactJson(['auth' => "test-key:{$signature}"]);
    }
});

it('refuses every channel of another workspace', function (string $suffix): void {
    // Chen of Globex with a valid session asks for Acme's channels.
    actingAsRole($this->globex, 'owner');
    $channel = str_replace(['{ticket}', '{user}'], [$this->ticket->id, createTenantUser($this->acme)->id], "tenants.{$this->acme->id}.{$suffix}");

    authorizeChannel($channel)->assertForbidden();
})->with(['tickets', 'tickets.{ticket}', 'tickets.{ticket}.internal', 'users.{user}']);

it('refuses a ticket of another workspace named under the own workspace', function (): void {
    actingAsRole($this->acme, 'owner');

    authorizeChannel("tenants.{$this->acme->id}.tickets.{$this->globexTicket->id}")->assertForbidden();
});

it('refuses another user\'s bell channel in the same workspace', function (): void {
    actingAsRole($this->acme, 'owner');
    $colleague = createTenantUser($this->acme);

    authorizeChannel("tenants.{$this->acme->id}.users.{$colleague->id}")->assertForbidden();
});

it('keeps the internal channel from a member without comments.internal', function (): void {
    // The developer role reads tickets but not internal notes.
    actingAsRole($this->acme, 'developer');
    $a = $this->acme->id;

    authorizeChannel("tenants.{$a}.tickets.{$this->ticket->id}")->assertOk();
    authorizeChannel("tenants.{$a}.tickets.{$this->ticket->id}.internal")->assertForbidden();
});

it('refuses members without tickets.view, unknown tickets and unknown channels', function (): void {
    $user = actingAsTenantUser($this->acme);
    $a = $this->acme->id;

    authorizeChannel("tenants.{$a}.tickets")->assertForbidden();
    authorizeChannel("tenants.{$a}.users.{$user->id}")->assertOk();

    actingAsRole($this->acme, 'agent');
    authorizeChannel("tenants.{$a}.tickets.not-a-uuid")->assertForbidden();
    authorizeChannel("tenants.{$a}.tickets.01999999-0000-7000-8000-000000000000")->assertForbidden();
    authorizeChannel("tenants.{$a}.tickets.{$this->ticket->id}.anything")->assertForbidden();
    authorizeChannel("tenants.{$a}.everything")->assertForbidden();
});

it('requires a signed-in session', function (): void {
    fromSpaOrigin();

    authorizeChannel("tenants.{$this->acme->id}.tickets")->assertUnauthorized();
});

it('refuses API clients whatever their scopes', function (): void {
    $this->withToken(issueToken(createApiClient($this->acme, ScopeMap::scopes())));

    authorizeChannel("tenants.{$this->acme->id}.tickets")->assertForbidden();
});

it('answers 404 when no WebSocket broadcaster is configured', function (): void {
    config(['broadcasting.default' => 'log']);
    actingAsRole($this->acme, 'agent');

    authorizeChannel("tenants.{$this->acme->id}.tickets")->assertNotFound();
});

it('validates the socket id and the channel name', function (): void {
    actingAsRole($this->acme, 'agent');

    $this->postJson('/v1/broadcasting/auth', ['socket_id' => 'nope', 'channel_name' => 'presence-x'])
        ->assertUnprocessable()
        ->assertJsonStructure(['errors' => ['socket_id', 'channel_name']]);
});
