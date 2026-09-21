<?php

declare(strict_types=1);

use App\Modules\Agents\Models\AgentProfile;
use App\Modules\Agents\Models\AgentShift;
use App\Modules\Agents\Models\AgentSkill;
use App\Modules\Agents\Models\Skill;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Models\Organization;
use App\Modules\Media\Models\MediaItem;
use App\Modules\Sla\Models\SlaPolicy;
use App\Modules\Sla\Models\TicketSlaTimer;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketAssignment;
use App\Modules\Tickets\Models\TicketComment;
use App\Modules\Tickets\Models\TicketDuplicateSuggestion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/ReportingHelpers.php';

if (function_exists('catalogueWorkspace')) {
    return;
}

/**
 * A workspace with enough of everything for every catalogue report: random ticket histories (1–10
 * September) plus organisations, SLA timers, assignments, duplicate suggestions, replies, skills, shifts,
 * media and audit entries, then `reports:rebuild` for the facts and snapshots. Seeded, so repeatable.
 *
 * @return list<Ticket>
 */
function catalogueWorkspace(Tenant $tenant, int $count, int $seed): array
{
    $tickets = randomHistories($tenant, $count, $seed);
    mt_srand($seed + 1);

    $tenant->run(function () use ($tickets): void {
        $organizations = [
            Organization::factory()->create(['tier' => 'premium']),
            Organization::factory()->create(['tier' => 'enterprise']),
        ];
        $policy = SlaPolicy::factory()->create();
        $agents = AgentProfile::query()->pluck('id')->all();
        $skill = Skill::factory()->create();
        AgentSkill::factory()->create(['agent_profile_id' => $agents[0], 'skill_id' => $skill->id, 'level' => 4]);
        AgentShift::factory()->create(['agent_profile_id' => $agents[0], 'weekday' => 1, 'starts_at' => '09:00:00', 'ends_at' => '17:00:00']);

        foreach ($tickets as $n => $ticket) {
            $created = CarbonImmutable::parse($ticket->created_at)->utc();
            $organization = $organizations[$n % 3] ?? null;
            if ($organization !== null) {
                Contact::query()->whereKey($ticket->contact_id)->update(['organization_id' => $organization->id]);
                $ticket->forceFill(['organization_id' => $organization->id])->saveQuietly();
            }
            if ($n % 4 === 0) {
                $ticket->category?->skills()->syncWithoutDetaching([$skill->id => ['tenant_id' => $ticket->tenant_id]]);
            }

            $state = ['met', 'breached', 'running', 'warning'][$n % 4];
            TicketSlaTimer::factory()->create([
                'ticket_id' => $ticket->id, 'policy_id' => $policy->id, 'kind' => $n % 2 === 0 ? 'first_response' : 'resolution',
                'state' => $state, 'started_at' => $created, 'warning_at' => $created->addMinutes(45), 'due_at' => $created->addHour(),
                'met_at' => $state === 'met' ? $created->addMinutes(30) : null,
                'breached_at' => $state === 'breached' ? $created->addHour() : null,
                'paused_total_seconds' => $n % 5 === 0 ? 600 : 0,
            ]);

            $reason = ['auto', 'manual', 'reassign', 'auto'][$n % 4];
            TicketAssignment::query()->create([
                'ticket_id' => $ticket->id, 'team_id' => $ticket->team_id, 'agent_profile_id' => $agents[$n % 2],
                'previous_agent_profile_id' => null, 'reason' => $reason, 'assigned_by_user_id' => null,
                'explanation' => ['strategy' => 'least_loaded_agent', 'outcome' => $n % 7 === 0 ? 'no_eligible_agent' : 'assigned', 'excluded' => []],
                'settings_version' => 1, 'created_at' => $created->addMinutes(5),
            ]);

            if ($n % 3 === 0 && isset($tickets[$n + 1])) {
                TicketDuplicateSuggestion::factory()->create([
                    'ticket_id' => $ticket->id, 'candidate_ticket_id' => $tickets[$n + 1]->id,
                    'decision' => ['pending', 'accepted', 'dismissed'][intdiv($n, 3) % 3],
                    'created_at' => $created->addMinute(),
                ]);
            }

            TicketComment::factory()->create(['ticket_id' => $ticket->id, 'created_at' => $created->addHours(2)]);
        }

        MediaItem::factory()->create(['state' => 'ready', 'size_bytes' => 2048, 'mime_type' => 'image/png', 'created_at' => '2026-09-05 10:00:00']);
        MediaItem::factory()->create(['state' => 'ready', 'size_bytes' => 100, 'created_at' => '2026-09-06 10:00:00']);
        MediaItem::factory()->create(['state' => 'pending', 'size_bytes' => 5000, 'created_at' => '2026-09-06 10:00:00']);

        foreach (['settings.updated', 'role.created', 'user.role_changed', 'settings.updated'] as $i => $action) {
            AuditLog::query()->create(['tenant_id' => tenant()?->getTenantKey(), 'actor_type' => 'user', 'actor_id' => null,
                'action' => $action, 'changes' => [], 'created_at' => CarbonImmutable::parse('2026-09-0'.($i + 2).' 08:00:00')]);
        }

        $organizations[0]->update(['tier' => 'enterprise']);
    });

    test()->artisan('reports:rebuild', ['--tenant' => $tenant->slug])->assertSuccessful();

    return $tickets;
}

/** The total a report returns, run as the current user through the API. */
function reportTotals(string $key, array $payload = []): array
{
    return test()->postJson("/v1/reports/{$key}/run", $payload)->assertOk()->json('data.totals');
}

/** Independent count over one table in the tenant and period. */
function countBetween(string $table, string $tenantId, string $column, CarbonImmutable $from, CarbonImmutable $to, ?Closure $where = null): int
{
    $query = DB::table($table)->where('tenant_id', $tenantId)->where($column, '>=', $from->utc())->where($column, '<', $to->utc());
    if ($where !== null) {
        $where($query);
    }

    return $query->count();
}
