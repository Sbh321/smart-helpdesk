<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Reporting\Models\EntityChange;
use App\Modules\Tickets\Models\Ticket;
use Carbon\CarbonImmutable;

// GET /v1/history/{type}/{id} and /as-of (ADR-0022 §5, security.md §History).

beforeEach(function (): void {
    $this->tenant = createTenant('history');
    $this->manager = actingAsRole($this->tenant, 'manager');
    tenancy()->initialize($this->tenant);
});

/** @return list<CarbonImmutable> occurred_at of each version, oldest first */
function versionTimes(string $type, string $id): array
{
    return EntityChange::query()->withoutTenancy()->where('entity_type', $type)->where('entity_id', $id)
        ->orderBy('version')->pluck('occurred_at')->map(fn ($at) => CarbonImmutable::parse($at))->all();
}

it('shows each earlier version of a contact edited three times', function (): void {
    $id = $this->postJson('/v1/contacts', ['name' => 'Asha Karki', 'email' => 'asha@example.test'])->assertCreated()->json('data.id');
    $this->patchJson("/v1/contacts/{$id}", ['name' => 'Asha K.'])->assertOk();
    $this->patchJson("/v1/contacts/{$id}", ['phone' => '+977 1 5550000'])->assertOk();
    $this->patchJson("/v1/contacts/{$id}", ['name' => 'Asha Karki Thapa', 'email' => 'asha.thapa@example.test'])->assertOk();
    $times = versionTimes('contacts', $id);
    expect($times)->toHaveCount(4);

    $asOf = fn (CarbonImmutable $at) => $this->getJson("/v1/history/contacts/{$id}/as-of?at=".urlencode($at->toIso8601ZuluString('microsecond')))
        ->assertOk()->json('data');

    $before = $asOf($times[0]->subSecond());
    expect($before['exists'])->toBeFalse()->and($before['attributes'])->toBeNull();

    $versions = array_map(fn (CarbonImmutable $at): array => $asOf($at)['attributes'], $times);
    expect(array_map(fn (array $v): array => [$v['name'], $v['email'], $v['phone']], $versions))->toBe([
        ['Asha Karki', 'asha@example.test', null],
        ['Asha K.', 'asha@example.test', null],
        ['Asha K.', 'asha@example.test', '+977 1 5550000'],
        ['Asha Karki Thapa', 'asha.thapa@example.test', '+977 1 5550000'],
    ]);

    $first = $asOf($times[0]);
    expect($first['differences'])->toMatchArray([
        'name' => ['then' => 'Asha Karki', 'now' => 'Asha Karki Thapa'],
        'email' => ['then' => 'asha@example.test', 'now' => 'asha.thapa@example.test'],
        'phone' => ['then' => null, 'now' => '+977 1 5550000'],
    ])->and($first['versions_after'])->toBe(3)
        ->and($asOf($times[3])['differences'])->toBe([]);
});

it('lists the change log newest first with who changed what', function (): void {
    $id = $this->postJson('/v1/contacts', ['name' => 'Bikram', 'email' => 'bikram@example.test'])->json('data.id');
    $this->patchJson("/v1/contacts/{$id}", ['name' => 'Bikram Rai'])->assertOk();

    $this->getJson("/v1/history/contacts/{$id}")->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.version', 2)
        ->assertJsonPath('data.0.operation', 'update')
        ->assertJsonPath('data.0.changes.name.old', 'Bikram')
        ->assertJsonPath('data.0.changes.name.new', 'Bikram Rai')
        ->assertJsonPath('data.0.actor_type', 'user')
        ->assertJsonPath('data.0.actor_id', $this->manager->id)
        ->assertJsonPath('data.1.operation', 'insert');
});

it('brings a deleted record back from its delete change', function (): void {
    $contact = Contact::factory()->forTenant($this->tenant)->create(['name' => 'Gone Soon']);
    tenancy()->initialize($this->tenant);
    $contact->delete();
    [$insert, $delete] = versionTimes('contacts', $contact->id);

    $this->getJson("/v1/history/contacts/{$contact->id}/as-of?at=".urlencode($insert->toIso8601ZuluString('microsecond')))
        ->assertOk()->assertJsonPath('data.exists', true)->assertJsonPath('data.attributes.name', 'Gone Soon');
    $this->getJson("/v1/history/contacts/{$contact->id}/as-of?at=".urlencode($delete->addSecond()->toIso8601ZuluString()))
        ->assertOk()->assertJsonPath('data.exists', false);
    $this->getJson("/v1/history/contacts/{$contact->id}")->assertOk()->assertJsonPath('data.0.operation', 'delete');
});

it('never shows columns capture does not record', function (): void {
    $user = createTenantUser($this->tenant, ['password' => 'a-long-password-1']);
    $ticket = Ticket::factory()->forTenant($this->tenant)->create();
    tenancy()->initialize($this->tenant);
    $this->postJson("/v1/tickets/{$ticket->id}/comments", ['body' => 'Secret-ish internal detail', 'visibility' => 'internal'])->assertCreated();
    $commentId = $ticket->comments()->withoutGlobalScopes()->value('id');
    $at = urlencode(now()->addMinute()->toIso8601ZuluString());

    // The owner can read user history; the manager cannot (users.manage).
    $this->getJson("/v1/history/users/{$user->id}/as-of?at={$at}")->assertForbidden();
    actingAsRole($this->tenant, 'owner', createTenantUser($this->tenant));
    tenancy()->initialize($this->tenant);
    $attributes = $this->getJson("/v1/history/users/{$user->id}/as-of?at={$at}")->assertOk()->json('data.attributes');
    expect($attributes)->toHaveKey('email')->not->toHaveKeys(['password', 'remember_token', 'updated_at']);

    $comment = $this->getJson("/v1/history/ticket_comments/{$commentId}/as-of?at={$at}")->assertOk()->json('data.attributes');
    expect($comment)->toHaveKey('visibility')->not->toHaveKey('body');
    $this->getJson("/v1/history/ticket_comments/{$commentId}")->assertOk()->assertJsonMissingPath('data.0.changes.body');
});

it('opens ticket history to agents and everything to managers, nothing to integrations', function (string $role, string $type, int $status): void {
    $contact = Contact::factory()->forTenant($this->tenant)->create();
    $ticket = Ticket::factory()->forTenant($this->tenant)->create();
    actingAsRole($this->tenant, $role, createTenantUser($this->tenant));
    tenancy()->initialize($this->tenant);
    $id = $type === 'tickets' ? $ticket->id : $contact->id;

    $this->getJson("/v1/history/{$type}/{$id}")->assertStatus($status);
})->with([
    'manager: tickets' => ['manager', 'tickets', 200],
    'manager: contacts' => ['manager', 'contacts', 200],
    'agent: tickets' => ['agent', 'tickets', 200],
    'agent: contacts' => ['agent', 'contacts', 403],
    'developer: tickets' => ['developer', 'tickets', 403],
]);

it('answers 404 for unknown subjects, unknown ids and records of another workspace', function (): void {
    $foreign = Contact::factory()->forTenant(createTenant('elsewhere'))->create();
    tenancy()->initialize($this->tenant);

    $this->getJson('/v1/history/sessions/01a0c000-0000-7000-8000-000000000001')->assertNotFound();
    $this->getJson('/v1/history/contacts/01a0c000-0000-7000-8000-000000000001')->assertNotFound();
    $this->getJson("/v1/history/contacts/{$foreign->id}")->assertNotFound();
    $this->getJson("/v1/history/contacts/{$foreign->id}/as-of?at=2026-09-21T00:00:00Z")->assertNotFound();
});

it('asks for a valid instant', function (string $at): void {
    $contact = Contact::factory()->forTenant($this->tenant)->create();
    tenancy()->initialize($this->tenant);

    $this->getJson("/v1/history/contacts/{$contact->id}/as-of?at={$at}")->assertStatus(422)->assertJsonStructure(['errors' => ['at']]);
})->with(['empty' => [''], 'nonsense' => ['yesterday-ish-o-clock']]);
