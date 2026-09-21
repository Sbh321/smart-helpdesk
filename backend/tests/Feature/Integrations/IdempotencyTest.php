<?php

declare(strict_types=1);

use App\Modules\Contacts\Models\Contact;
use App\Modules\Integrations\Models\IdempotencyKey;
use App\Modules\Tickets\Models\Ticket;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;

require_once __DIR__.'/IntegrationTestHelpers.php';
require_once __DIR__.'/../Tickets/TicketTestHelpers.php';

/*
 * Idempotency-Key on POST /v1/tickets for API clients (docs/07-api/conventions.md §Idempotency).
 */

beforeEach(function (): void {
    $this->clock = new FrozenClock('2026-09-21 10:00:00');
    $this->app->instance(Clock::class, $this->clock);
    $this->acme = createTenant('acme');
    [$this->contact, $this->category] = ticketPrerequisites($this->acme);
    $this->client = createApiClient($this->acme, ['tickets:write']);
    $this->withToken(issueToken($this->client));
});

function idempotentPayload(array $overrides = []): array
{
    return [
        'title' => 'Disk full on db-01',
        'description' => 'Usage at 98 %.',
        'contact_id' => test()->contact->id,
        'category_id' => test()->category->id,
        'impact' => 3,
        'urgency' => 4,
        ...$overrides,
    ];
}

it('returns the first response again for a repeated key and creates one ticket', function (): void {
    $first = $this->postJson('/v1/tickets', idempotentPayload(), ['Idempotency-Key' => 'key-1'])->assertCreated();
    $second = $this->postJson('/v1/tickets', idempotentPayload(), ['Idempotency-Key' => 'key-1'])
        ->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'true');

    expect($second->json())->toBe($first->json())
        ->and(Ticket::query()->withoutTenancy()->count())->toBe(1)
        ->and(IdempotencyKey::query()->withoutTenancy()->sole()->key_hash)->toBe(hash('sha256', 'key-1'));
});

it('treats the same body with keys in another order as the same request', function (): void {
    $this->postJson('/v1/tickets', idempotentPayload(), ['Idempotency-Key' => 'key-2'])->assertCreated();
    $this->postJson('/v1/tickets', array_reverse(idempotentPayload(), true), ['Idempotency-Key' => 'key-2'])
        ->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'true');

    expect(Ticket::query()->withoutTenancy()->count())->toBe(1);
});

it('rejects the same key with a different body', function (): void {
    $this->postJson('/v1/tickets', idempotentPayload(), ['Idempotency-Key' => 'key-3'])->assertCreated();

    $this->postJson('/v1/tickets', idempotentPayload(['title' => 'Another problem']), ['Idempotency-Key' => 'key-3'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'idempotency_key_reused');

    expect(Ticket::query()->withoutTenancy()->count())->toBe(1);
});

it('creates a new ticket without a key or with a new key', function (): void {
    $this->postJson('/v1/tickets', idempotentPayload())->assertCreated();
    $this->postJson('/v1/tickets', idempotentPayload())->assertCreated();
    $this->postJson('/v1/tickets', idempotentPayload(), ['Idempotency-Key' => 'key-4'])->assertCreated();

    expect(Ticket::query()->withoutTenancy()->count())->toBe(3);
});

it('forgets a key after 24 hours', function (): void {
    $this->postJson('/v1/tickets', idempotentPayload(), ['Idempotency-Key' => 'key-5'])->assertCreated();
    $this->clock->advance('24 hours 1 second');

    $this->postJson('/v1/tickets', idempotentPayload(), ['Idempotency-Key' => 'key-5'])
        ->assertCreated()
        ->assertHeaderMissing('Idempotent-Replayed');

    expect(Ticket::query()->withoutTenancy()->count())->toBe(2)
        ->and(IdempotencyKey::query()->withoutTenancy()->count())->toBe(1);
});

it('keeps keys apart per client', function (): void {
    $this->postJson('/v1/tickets', idempotentPayload(), ['Idempotency-Key' => 'shared'])->assertCreated();

    $other = createApiClient($this->acme, ['tickets:write']);
    $this->withToken(issueToken($other))
        ->postJson('/v1/tickets', idempotentPayload(), ['Idempotency-Key' => 'shared'])
        ->assertCreated()
        ->assertHeaderMissing('Idempotent-Replayed');

    expect(Ticket::query()->withoutTenancy()->count())->toBe(2);
});

it('does not store failed responses, so a corrected retry may reuse the key', function (): void {
    $this->postJson('/v1/tickets', idempotentPayload(['impact' => 9]), ['Idempotency-Key' => 'key-6'])->assertUnprocessable();
    $this->postJson('/v1/tickets', idempotentPayload(), ['Idempotency-Key' => 'key-6'])->assertCreated();

    expect(Ticket::query()->withoutTenancy()->count())->toBe(1);
});

it('rejects a malformed key', function (): void {
    $this->postJson('/v1/tickets', idempotentPayload(), ['Idempotency-Key' => str_repeat('x', 256)])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation_failed');
});

it('honours the key on POST /v1/contacts as well', function (): void {
    $client = createApiClient($this->acme, ['contacts:write']);
    $this->withToken(issueToken($client));
    $payload = ['name' => 'Ops team', 'email' => 'ops@globex.test'];

    $first = $this->postJson('/v1/contacts', $payload, ['Idempotency-Key' => 'contact-1'])->assertCreated();
    $this->postJson('/v1/contacts', $payload, ['Idempotency-Key' => 'contact-1'])
        ->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('data.id', $first->json('data.id'));

    expect(Contact::query()->withoutTenancy()->where('email', 'ops@globex.test')->count())->toBe(1);
});
