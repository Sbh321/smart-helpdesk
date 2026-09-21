<?php

declare(strict_types=1);

use App\Modules\Agents\Enums\AgentAvailability;
use App\Modules\Agents\Models\AgentProfile;
use App\Modules\Agents\Models\AgentShift;
use App\Modules\Agents\Models\Skill;
use App\Modules\Agents\Models\Team;
use App\Modules\Automation\Queries\AgentWorkload;
use App\Modules\Automation\Queries\AssignmentCandidates;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tickets\Domain\Priority;
use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Models\Category;
use App\Modules\Tickets\Models\Ticket;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->tenant = createTenant('candidates', ['timezone' => 'Asia/Kathmandu']);
    $this->app->instance(Clock::class, new FrozenClock('2026-09-21 03:30:00'));
});

function candidateTicket(Tenant $tenant, Category $category, array $attributes = []): Ticket
{
    $status = $attributes['status'] ?? TicketStatus::Open;

    return Ticket::factory()->forTenant($tenant)->create([
        'category_id' => $category->id,
        'resolved_at' => in_array($status, [TicketStatus::Resolved, TicketStatus::Closed], true) ? now() : null,
        ...$attributes,
    ]);
}

it('maps category needs and every tenant agent into a stable strategy pool', function (): void {
    $this->tenant->run(function (): void {
        $billing = Skill::factory()->create(['name' => 'Billing', 'slug' => 'billing']);
        $refunds = Skill::factory()->create(['name' => 'Refunds', 'slug' => 'refunds']);
        $team = Team::factory()->create(['name' => 'Accounts']);
        $category = Category::factory()->create(['default_team_id' => $team->id]);
        $category->skills()->attach([
            $refunds->id => ['tenant_id' => $this->tenant->id],
            $billing->id => ['tenant_id' => $this->tenant->id],
        ]);
        $ticket = candidateTicket($this->tenant, $category);

        $eligible = AgentProfile::factory()->create(['capacity' => 8]);
        $eligible->skills()->attach([
            $refunds->id => ['tenant_id' => $this->tenant->id, 'level' => 2],
            $billing->id => ['tenant_id' => $this->tenant->id, 'level' => 4],
        ]);
        $eligible->teams()->attach($team->id, ['tenant_id' => $this->tenant->id, 'joined_at' => now()]);

        $inactive = AgentProfile::factory()->create([
            'availability' => AgentAvailability::Away,
            'capacity' => 3,
        ]);
        $inactive->user->forceFill(['is_active' => false])->save();

        // Workload comes from the stored counter, never from a ticket count at read time.
        $eligible->forceFill(['active_ticket_count' => 3])->save();

        $pool = app(AssignmentCandidates::class)->forTicket($ticket);

        expect($pool->needs->requiredSkills)->toBe(['billing', 'refunds'])
            ->and($pool->needs->teamId)->toBe($team->id)
            ->and($pool->needs->enforceShifts)->toBeFalse()
            ->and(array_column($pool->candidates, 'id'))->toBe(collect($pool->candidates)->pluck('id')->sort()->values()->all());

        $candidate = collect($pool->candidates)->firstWhere('id', $eligible->id);
        $inactiveCandidate = collect($pool->candidates)->firstWhere('id', $inactive->id);

        expect($candidate?->openTickets)->toBe(3)
            ->and($candidate?->skills)->toBe(['billing', 'refunds'])
            ->and($candidate?->teamIds)->toBe([$team->id])
            ->and($candidate?->active)->toBeTrue()
            ->and($candidate?->available)->toBeTrue()
            ->and($inactiveCandidate?->active)->toBeFalse()
            ->and($inactiveCandidate?->available)->toBeFalse();
    });
});

it('uses date exceptions ahead of weekly shifts in the tenant timezone', function (): void {
    $this->tenant->run(function (): void {
        config(['helpdesk.shifts.enforce' => true]);

        $category = Category::factory()->create();
        $ticket = candidateTicket($this->tenant, $category);
        $working = AgentProfile::factory()->create();
        $off = AgentProfile::factory()->create();

        foreach ([$working, $off] as $agent) {
            AgentShift::factory()->create([
                'agent_profile_id' => $agent->id,
                'weekday' => 1,
                'starts_at' => '09:00:00',
                'ends_at' => '17:00:00',
            ]);
        }
        AgentShift::factory()->create([
            'agent_profile_id' => $off->id,
            'weekday' => null,
            'date' => '2026-09-21',
            'starts_at' => '00:00:00',
            'ends_at' => '23:59:59',
            'is_off' => true,
        ]);

        $pool = app(AssignmentCandidates::class)->forTicket($ticket);
        $candidates = collect($pool->candidates)->keyBy('id');

        expect($pool->needs->enforceShifts)->toBeTrue()
            ->and($candidates[$working->id]->onShift)->toBeTrue()
            ->and($candidates[$off->id]->onShift)->toBeFalse();
    });
});

it('returns workload counts and effective priority buckets from active assignment statuses', function (): void {
    $this->tenant->run(function (): void {
        $agent = AgentProfile::factory()->create(['capacity' => 4]);
        $category = Category::factory()->create();

        candidateTicket($this->tenant, $category, [
            'assigned_agent_id' => $agent->id,
            'status' => TicketStatus::Assigned,
            'priority_level' => Priority::P1,
        ]);
        candidateTicket($this->tenant, $category, [
            'assigned_agent_id' => $agent->id,
            'status' => TicketStatus::Pending,
            'priority_level' => Priority::P4,
            'priority_override_level' => Priority::P2,
            'priority_override_reason' => 'Escalated customer',
        ]);
        candidateTicket($this->tenant, $category, [
            'assigned_agent_id' => $agent->id,
            'status' => TicketStatus::Resolved,
            'priority_level' => Priority::P3,
        ]);

        expect(app(AgentWorkload::class)->for($agent))->toBe([
            'active_ticket_count' => 2,
            'capacity' => 4,
            'load' => 0.5,
            'by_priority' => ['P1' => 1, 'P2' => 1, 'P3' => 0, 'P4' => 0],
        ]);
    });
});

it('never lets the settings of another workspace switch shift enforcement on', function (): void {
    $other = createTenant('candidates-b');
    // Workspace B stores `shifts.enforce = true`; the row is older, so an unscoped read finds it first.
    DB::table('tenant_settings')->insert([
        'id' => (string) str()->uuid(),
        'tenant_id' => $other->id,
        'data' => json_encode(['shifts' => ['enforce' => true]], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $other->run(fn () => AgentProfile::factory()->count(2)->create());

    $this->tenant->run(function (): void {
        $agent = AgentProfile::factory()->create(); // no shifts at all: off shift if enforcement leaked
        $ticket = candidateTicket($this->tenant, Category::factory()->create());

        $pool = app(AssignmentCandidates::class)->forTicket($ticket);

        expect($pool->needs->enforceShifts)->toBeFalse()
            ->and(array_column($pool->candidates, 'id'))->toBe([$agent->id])
            ->and($pool->candidates[0]->onShift)->toBeTrue();
    });
});
