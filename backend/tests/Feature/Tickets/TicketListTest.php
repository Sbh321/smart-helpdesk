<?php

declare(strict_types=1);

use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Models\Organization;
use App\Modules\Tickets\Domain\Priority;
use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Models\Category;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketEvent;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/TicketTestHelpers.php';

beforeEach(function (): void {
    $this->acme = createTenant('acme', ['timezone' => 'Asia/Kathmandu']);
    $this->globex = createTenant('globex');
    actingAsRole($this->acme, 'agent');

    $this->org = Organization::factory()->forTenant($this->acme)->create();
    $this->contact = Contact::factory()->forTenant($this->acme)->create(['organization_id' => $this->org->id]);
    $this->other = Contact::factory()->forTenant($this->acme)->create();
    $this->billing = Category::factory()->forTenant($this->acme)->create(['name' => 'Billing']);
    $this->tech = Category::factory()->forTenant($this->acme)->create(['name' => 'Technical']);

    $make = fn (array $attributes, ?Contact $contact = null, ?Category $category = null) => Ticket::factory()
        ->forContact($contact ?? $this->contact, $category ?? $this->billing)
        ->create($attributes);

    $this->t1 = $make(['number' => 1, 'title' => 'Printers are offline on floor two', 'description' => 'Nothing prints.', 'priority_score' => 90, 'priority_level' => Priority::P1, 'impact' => 4, 'created_at' => '2026-09-10 04:00:00']);
    $this->t2 = $make(['number' => 2, 'title' => 'Invoice total is wrong', 'description' => 'The refund was not applied.', 'priority_score' => 40, 'priority_level' => Priority::P3, 'impact' => 2, 'status' => TicketStatus::Pending, 'created_at' => '2026-09-12 10:00:00'], $this->other, $this->tech);
    $this->t3 = $make(['number' => 3, 'title' => 'VPN drops', 'description' => 'Connection resets hourly.', 'priority_score' => 60, 'priority_level' => Priority::P2, 'impact' => 1, 'priority_override_level' => Priority::P1, 'status' => TicketStatus::Resolved, 'resolved_at' => '2026-09-15 00:00:00', 'assigned_agent_id' => '0199aaaa-0000-7000-8000-00000000a001', 'created_at' => '2026-09-14 20:00:00']);

    [$foreignContact, $foreignCategory] = ticketPrerequisites($this->globex);
    Ticket::factory()->forContact($foreignContact, $foreignCategory)->create(['number' => 1, 'title' => 'Printers broken at Globex']);
});

function ticketNumbers(TestResponse $response): array
{
    return array_column($response->assertOk()->json('data'), 'number');
}

it('lists only this workspace, ordered by priority score by default', function (): void {
    expect(ticketNumbers($this->getJson('/v1/tickets')))->toBe([1, 3, 2]);
});

it('paginates with meta', function (): void {
    $this->getJson('/v1/tickets?per_page=2&page=2')
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonCount(1, 'data');
});

it('sorts by each allowed field', function (string $sort, array $expected): void {
    expect(ticketNumbers($this->getJson("/v1/tickets?sort={$sort}")))->toBe($expected);
})->with([
    'number' => ['number', [1, 2, 3]],
    'number desc' => ['-number', [3, 2, 1]],
    'created_at' => ['created_at', [1, 2, 3]],
    'effective priority' => ['priority_level,number', [1, 3, 2]],
    'status then number' => ['status,number', [1, 2, 3]],
]);

it('filters by status, including the active alias', function (): void {
    expect(ticketNumbers($this->getJson('/v1/tickets?filter[status]=pending')))->toBe([2])
        ->and(ticketNumbers($this->getJson('/v1/tickets?filter[status]=active&sort=number')))->toBe([1, 2])
        ->and(ticketNumbers($this->getJson('/v1/tickets?filter[status]=open,resolved&sort=number')))->toBe([1, 3]);
});

it('filters by effective priority, honouring overrides', function (): void {
    expect(ticketNumbers($this->getJson('/v1/tickets?filter[priority]=P1&sort=number')))->toBe([1, 3]);
});

it('filters by assignee, category, organisation, contact, impact and number', function (): void {
    expect(ticketNumbers($this->getJson('/v1/tickets?filter[assignee_id]=unassigned&sort=number')))->toBe([1, 2])
        ->and(ticketNumbers($this->getJson("/v1/tickets?filter[category_id]={$this->tech->id}")))->toBe([2])
        ->and(ticketNumbers($this->getJson("/v1/tickets?filter[organization_id]={$this->org->id}&sort=number")))->toBe([1, 3])
        ->and(ticketNumbers($this->getJson("/v1/tickets?filter[contact_id]={$this->other->id}")))->toBe([2])
        ->and(ticketNumbers($this->getJson('/v1/tickets?filter[impact]=4')))->toBe([1])
        ->and(ticketNumbers($this->getJson('/v1/tickets?filter[number]=3')))->toBe([3]);
});

it('filters by tag', function (): void {
    $this->acme->run(fn () => $this->t2->syncTagNames(['billing-dispute']));

    expect(ticketNumbers($this->getJson('/v1/tickets?filter[tag]=billing-dispute')))->toBe([2]);
});

it('filters a date range in the workspace time zone, inclusive', function (): void {
    // Ticket 3 was created at 20:00 UTC on the 14th, which is already the 15th in Kathmandu (+05:45).
    expect(ticketNumbers($this->getJson('/v1/tickets?filter[created_between]=2026-09-12,2026-09-14&sort=number')))->toBe([2])
        ->and(ticketNumbers($this->getJson('/v1/tickets?filter[created_between]=2026-09-15,2026-09-15')))->toBe([3]);
});

it('finds tickets by word stem, number and title fragment', function (): void {
    expect(ticketNumbers($this->getJson('/v1/tickets?search=printer')))->toBe([1])
        ->and(ticketNumbers($this->getJson('/v1/tickets?search=refunds')))->toBe([2])
        ->and(ticketNumbers($this->getJson('/v1/tickets?search=2')))->toBe([2])
        ->and(ticketNumbers($this->getJson('/v1/tickets?search=VPN')))->toBe([3])
        ->and(ticketNumbers($this->getJson('/v1/tickets?search=globex')))->toBe([]);
});

it('embeds requested relations', function (): void {
    $this->getJson('/v1/tickets?include=contact,category,organization,tags&filter[number]=1')
        ->assertJsonPath('data.0.contact.id', $this->contact->id)
        ->assertJsonPath('data.0.category.name', 'Billing')
        ->assertJsonPath('data.0.organization.id', $this->org->id)
        ->assertJsonPath('data.0.tags', []);

    $this->getJson('/v1/tickets?filter[number]=1')->assertJsonMissingPath('data.0.contact');
});

it('rejects unknown filters, sorts, includes and bad ranges', function (string $query, string $field): void {
    $this->getJson("/v1/tickets?{$query}")->assertStatus(422)->assertJsonStructure(['errors' => [$field]]);
})->with([
    'filter' => ['filter[secret]=1', 'filter.secret'],
    'status value' => ['filter[status]=new', 'filter.status'],
    'sort' => ['sort=title', 'sort'],
    'include' => ['include=assignee', 'include'],
    'range order' => ['filter[created_between]=2026-09-15,2026-09-01', 'filter.created_between'],
]);

it('shows a ticket and its history, newest first', function (): void {
    $this->acme->run(function (): void {
        TicketEvent::query()->create(['ticket_id' => $this->t1->id, 'type' => 'created', 'actor_type' => 'system', 'created_at' => '2026-09-10 04:00:00']);
        TicketEvent::query()->create(['ticket_id' => $this->t1->id, 'type' => 'status_changed', 'actor_type' => 'system', 'old_values' => ['status' => 'open'], 'new_values' => ['status' => 'assigned'], 'created_at' => '2026-09-10 05:00:00']);
    });

    $this->getJson("/v1/tickets/{$this->t1->id}")
        ->assertOk()
        ->assertJsonPath('data.number', 1)
        ->assertJsonPath('data.contact.id', $this->contact->id);

    $this->getJson("/v1/tickets/{$this->t1->id}/history")
        ->assertOk()
        ->assertJsonPath('data.0.type', 'status_changed')
        ->assertJsonPath('data.1.type', 'created')
        ->assertJsonStructure(['meta' => ['next_cursor', 'per_page']]);
});

it('answers 404 for another workspace ticket', function (): void {
    $foreign = Ticket::query()->withoutTenancy()->where('tenant_id', $this->globex->id)->sole();

    $this->getJson("/v1/tickets/{$foreign->id}")->assertNotFound();
    $this->getJson("/v1/tickets/{$foreign->id}/history")->assertNotFound();
});

it('lists the categories for the create form', function (): void {
    $this->getJson('/v1/categories')->assertOk()->assertJsonCount(2, 'data');
});
