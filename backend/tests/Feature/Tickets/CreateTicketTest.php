<?php

declare(strict_types=1);

use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Models\Organization;
use App\Modules\Tickets\Actions\AllocateTicketNumber;
use App\Modules\Tickets\Actions\CreateTicket;
use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Models\Category;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketEvent;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/TicketTestHelpers.php';

beforeEach(function (): void {
    $this->app->instance(Clock::class, new FrozenClock('2026-09-18 09:00:00'));
    $this->acme = createTenant('acme');
    $this->globex = createTenant('globex');
    $this->user = actingAsRole($this->acme, 'agent');
    [$this->contact, $this->category] = ticketPrerequisites($this->acme);
});

function newTicketPayload(array $overrides = []): array
{
    return [
        'title' => 'Printer on floor 3 is jammed',
        'description' => 'The printer shows error E-34 and nobody can print invoices.',
        'contact_id' => test()->contact->id,
        'category_id' => test()->category->id,
        'impact' => 3,
        'urgency' => 2,
        ...$overrides,
    ];
}

it('creates an open ticket with the next number, history and includes', function (): void {
    $organization = Organization::factory()->forTenant($this->acme)->create();
    $this->contact->forceFill(['organization_id' => $organization->id])->save();

    $response = $this->postJson('/v1/tickets', newTicketPayload(['tags' => ['hardware']]));

    $response->assertCreated()
        ->assertJsonPath('data.number', 1)
        ->assertJsonPath('data.status', 'open')
        // impact 3, urgency 2, standard tier: 100 × (0.40 × 2/3 + 0.35 × 1/3) = 38.3, which is P3.
        ->assertJsonPath('data.priority_level', 'P3')
        ->assertJsonPath('data.priority_score', 38.3)
        ->assertJsonPath('data.organization_id', $organization->id)
        ->assertJsonPath('data.contact.id', $this->contact->id)
        ->assertJsonPath('data.category.name', $this->category->name)
        ->assertJsonPath('data.tags.0.slug', 'hardware')
        ->assertJsonPath('data.created_via', 'ui')
        ->assertJsonPath('data.created_at', '2026-09-18T09:00:00Z');

    $ticket = Ticket::query()->withoutTenancy()->findOrFail($response->json('data.id'));
    $event = TicketEvent::query()->withoutTenancy()->where('ticket_id', $ticket->id)->where('type', 'created')->sole();

    expect($ticket->tenant_id)->toBe($this->acme->id)
        ->and($ticket->created_by_user_id)->toBe($this->user->id)
        ->and($event->type)->toBe('created')
        ->and($event->actor_type)->toBe('user')
        ->and($event->new_values['number'])->toBe(1)
        ->and($this->contact->fresh()->last_ticket_at?->toIso8601String())->toBe('2026-09-18T09:00:00+00:00');
});

it('numbers tickets per workspace without gaps', function (): void {
    foreach (range(1, 3) as $expected) {
        $this->postJson('/v1/tickets', newTicketPayload())->assertCreated()->assertJsonPath('data.number', $expected);
    }

    [$globexContact, $globexCategory] = ticketPrerequisites($this->globex);
    $ticket = $this->globex->run(fn () => app(CreateTicket::class)([
        'title' => 'x', 'description' => 'y', 'contact_id' => $globexContact->id,
        'category_id' => $globexCategory->id, 'impact' => 1, 'urgency' => 1,
    ]));

    expect($ticket->number)->toBe(1);
});

it('returns the number when the creating transaction rolls back', function (): void {
    $this->postJson('/v1/tickets', newTicketPayload())->assertJsonPath('data.number', 1);

    try {
        DB::transaction(function (): void {
            $this->acme->run(fn () => app(AllocateTicketNumber::class)($this->acme->id));

            throw new RuntimeException('failed after allocation');
        });
    } catch (RuntimeException) {
        // expected
    }

    $this->postJson('/v1/tickets', newTicketPayload())->assertJsonPath('data.number', 2);
});

it('validates the payload', function (array $overrides, string $field): void {
    $this->postJson('/v1/tickets', newTicketPayload($overrides))
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => [$field]]);
})->with([
    'missing title' => [['title' => ''], 'title'],
    'impact too high' => [['impact' => 5], 'impact'],
    'urgency too low' => [['urgency' => 0], 'urgency'],
    'unknown contact' => [['contact_id' => '0199aaaa-0000-7000-8000-000000000000'], 'contact_id'],
]);

it('refuses a contact or category of another workspace', function (): void {
    [$foreignContact, $foreignCategory] = ticketPrerequisites($this->globex);

    $this->postJson('/v1/tickets', newTicketPayload(['contact_id' => $foreignContact->id]))->assertStatus(422)->assertJsonStructure(['errors' => ['contact_id']]);
    $this->postJson('/v1/tickets', newTicketPayload(['category_id' => $foreignCategory->id]))->assertStatus(422)->assertJsonStructure(['errors' => ['category_id']]);
});

it('refuses an archived contact and an inactive category', function (): void {
    $archived = Contact::factory()->forTenant($this->acme)->archived()->create();
    $inactive = Category::factory()->forTenant($this->acme)->create(['is_active' => false]);

    $this->postJson('/v1/tickets', newTicketPayload(['contact_id' => $archived->id]))->assertStatus(422);
    $this->postJson('/v1/tickets', newTicketPayload(['category_id' => $inactive->id]))->assertStatus(422);
});

it('lets a developer read tickets but not create them', function (): void {
    actingAsRole($this->acme, 'developer', createTenantUser($this->acme));

    $this->getJson('/v1/tickets')->assertOk();
    $this->postJson('/v1/tickets', newTicketPayload())->assertForbidden();
});

it('records ticket changes for history and reporting', function (): void {
    $id = $this->postJson('/v1/tickets', newTicketPayload())->json('data.id');

    // Version 1 is the insert; scoring the priority in the same transaction is version 2.
    $operations = DB::table('entity_changes')->where('entity_type', 'tickets')->where('entity_id', $id)
        ->orderBy('version')->pluck('operation')->all();

    expect($operations[0])->toBe('insert')->and(array_unique(array_slice($operations, 1)))->toBe(['update']);
});

it('keeps resolved_at consistent with the status in the database', function (): void {
    $ticket = Ticket::factory()->forContact($this->contact, $this->category)->create();

    DB::table('tickets')->where('id', $ticket->id)->update(['status' => TicketStatus::Resolved->value]);
})->throws(QueryException::class, 'tickets_resolved_at_check');
