<?php

declare(strict_types=1);

use App\Modules\Agents\Models\AgentProfile;
use App\Modules\Agents\Models\Skill;
use App\Modules\Agents\Models\Team;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Models\Organization;
use App\Modules\Reporting\Models\ReportTicketFact;
use App\Modules\Reporting\Models\ReportTicketInterval;
use App\Modules\Tickets\Models\Category;
use App\Modules\Tickets\Models\Ticket;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/ReportingHelpers.php';

// GET /v1/{entity}/{id}/overview (docs/04-domain/reporting.md §Entity 360, roadmap M3-02).

beforeEach(function (): void {
    $this->app->instance(Clock::class, new FrozenClock('2026-09-21 06:00:00'));
    $this->tenant = createTenant('overview', ['timezone' => 'Asia/Kathmandu']);
    $this->tickets = randomHistories($this->tenant, 20, 5);
    actingAsRole($this->tenant, 'manager');
    tenancy()->initialize($this->tenant);
});

it('traces a ticket\'s lifecycle from its intervals, with the open one running to now', function (): void {
    $ticket = $this->tickets[0];
    $stored = ReportTicketInterval::query()->where('ticket_id', $ticket->id)->orderBy('seq')->get();

    $data = $this->getJson("/v1/tickets/{$ticket->id}/overview")->assertOk()->json('data');

    expect($data['entity'])->toBe('tickets')
        ->and($data['title'])->toBe("#{$ticket->number} {$ticket->title}")
        ->and(array_column($data['trends']['lifecycle'], 'status'))->toBe($stored->pluck('status')->all())
        ->and(array_column($data['trends']['lifecycle'], 'seq'))->toBe($stored->pluck('seq')->all());
    $last = end($data['trends']['lifecycle']);
    expect($last['open'])->toBeTrue()
        ->and($last['wall_seconds'])->toBe(CarbonImmutable::parse('2026-09-21 06:00:00')->getTimestamp() - $stored->last()->starts_at->getTimestamp());
});

it('sums a contact\'s and an organisation\'s tickets from the facts', function (): void {
    $organization = Organization::factory()->forTenant($this->tenant)->create(['tier' => 'standard']);
    $contact = Contact::factory()->forTenant($this->tenant)->create(['organization_id' => $organization->id]);
    $five = array_map(fn (Ticket $ticket): string => $ticket->id, array_slice($this->tickets, 0, 5));
    Ticket::query()->whereKey($five)->update(['contact_id' => $contact->id, 'organization_id' => $organization->id]);
    ReportTicketFact::query()->whereIn('ticket_id', $five)->update(['contact_id' => $contact->id, 'organization_id' => $organization->id]);
    $open = ReportTicketFact::query()->whereIn('ticket_id', $five)->whereNull('resolved_at')->whereNull('closed_at')->count();
    $organization->forceFill(['tier' => 'premium'])->save();

    $person = $this->getJson("/v1/contacts/{$contact->id}/overview")->assertOk()->json('data');
    expect($person['metrics']['tickets'])->toBe(5)
        ->and($person['metrics']['open_tickets'])->toBe($open)
        ->and($person['related']['organization_id'])->toBe($organization->id)
        ->and($person['related']['recent_tickets'])->toHaveCount(5)
        ->and($person['trends']['tickets_per_week'])->toHaveCount(12);

    $company = $this->getJson("/v1/organizations/{$organization->id}/overview")->assertOk()->json('data');
    expect($company['metrics'])->toMatchArray(['tier' => 'premium', 'contacts' => 1, 'tickets' => 5])
        // Creation counts as the first tier, then the change.
        ->and(array_map(fn (array $step): array => [$step['from'], $step['to']], $company['related']['tier_history']))
        ->toBe([[null, 'standard'], ['standard', 'premium']])
        ->and(array_sum(array_column($company['related']['top_categories'], 'tickets')))->toBe(5);
});

it('shows an agent\'s skills, teams, queue and 30-day performance, and a team\'s members', function (): void {
    $agentId = $this->tickets[0]->refresh()->assigned_agent_id
        ?? collect($this->tickets)->map->refresh()->firstWhere('assigned_agent_id', '!=', null)?->assigned_agent_id;
    $agent = AgentProfile::query()->findOrFail($agentId);
    $skill = Skill::factory()->forTenant($this->tenant)->create(['name' => 'Networking']);
    DB::table('agent_skills')->insert(['tenant_id' => $this->tenant->id, 'agent_profile_id' => $agent->id, 'skill_id' => $skill->id, 'level' => 3]);
    $team = Team::factory()->forTenant($this->tenant)->create(['name' => 'Night shift']);
    DB::table('team_members')->insert(['tenant_id' => $this->tenant->id, 'team_id' => $team->id, 'agent_profile_id' => $agent->id, 'joined_at' => now()]);
    tenancy()->initialize($this->tenant);

    $data = $this->getJson("/v1/agents/{$agent->id}/overview")->assertOk()->json('data');
    expect($data['related']['skills'])->toBe([['id' => $skill->id, 'name' => 'Networking', 'level' => 3]])
        ->and(array_column($data['related']['teams'], 'name'))->toContain('Night shift')
        ->and($data['metrics'])->toHaveKeys(['capacity', 'open_tickets', 'assigned_30d', 'resolved_30d', 'sla_compliance_30d']);

    $crew = $this->getJson("/v1/teams/{$team->id}/overview")->assertOk()->json('data');
    expect(array_column($crew['related']['members'], 'id'))->toBe([$agent->id]);
});

it('lists a category\'s required skills, volume and top agents', function (): void {
    $category = Category::query()->findOrFail($this->tickets[0]->category_id);
    $skill = Skill::factory()->forTenant($this->tenant)->create(['name' => 'Billing']);
    DB::table('category_skill')->insert(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'skill_id' => $skill->id]);
    tenancy()->initialize($this->tenant);

    $data = $this->getJson("/v1/categories/{$category->id}/overview")->assertOk()->json('data');
    expect($data['related']['required_skills'][0]['name'])->toBe('Billing')
        ->and($data['metrics']['tickets'])->toBe(ReportTicketFact::query()->where('category_id', $category->id)->count());
});

it('answers 404 across workspaces and needs the entity\'s view permission', function (): void {
    $foreign = Contact::factory()->forTenant(createTenant('elsewhere-overview'))->create();
    tenancy()->initialize($this->tenant);
    $this->getJson("/v1/contacts/{$foreign->id}/overview")->assertNotFound();
    $this->getJson('/v1/tickets/01a0c000-0000-7000-8000-000000000001/overview')->assertNotFound();

    $viewer = createTenantUser($this->tenant);
    $this->tenant->run(function () use ($viewer): void {
        setPermissionsTeamId($this->tenant->getTenantKey());
        $viewer->givePermissionTo(['tickets.view']);
    });
    actingAsTenantUser($this->tenant, $viewer);
    tenancy()->initialize($this->tenant);
    $this->getJson("/v1/tickets/{$this->tickets[0]->id}/overview")->assertOk();
    $this->getJson("/v1/contacts/{$this->tickets[0]->contact_id}/overview")->assertForbidden();
    $this->getJson("/v1/agents/{$this->tickets[0]->id}/overview")->assertForbidden();
});
