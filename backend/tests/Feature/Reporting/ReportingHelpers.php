<?php

declare(strict_types=1);

use App\Modules\Agents\Models\AgentProfile;
use App\Modules\Agents\Models\Team;
use App\Modules\Reporting\Support\TicketReportWriter;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketEvent;
use Carbon\CarbonImmutable;

if (function_exists('randomHistories')) {
    return;
}

/**
 * Seeded random ticket histories between 1 and 10 September: status walks along the lifecycle, the
 * assignee and team change now and then. Each ticket's current row matches its last event.
 *
 * @return list<Ticket>
 */
function randomHistories(Tenant $tenant, int $count, int $seed): array
{
    mt_srand($seed);
    $teams = [Team::factory()->forTenant($tenant)->create()->id, Team::factory()->forTenant($tenant)->create()->id];
    $agents = [AgentProfile::factory()->forTenant($tenant)->create()->id, AgentProfile::factory()->forTenant($tenant)->create()->id];
    $next = ['open' => ['assigned'], 'assigned' => ['in_progress'], 'in_progress' => ['pending', 'resolved'],
        'pending' => ['in_progress'], 'resolved' => ['closed', 'in_progress'], 'closed' => []];
    $tickets = [];

    for ($n = 0; $n < $count; $n++) {
        $at = CarbonImmutable::parse('2026-09-01 00:00:00')->addSeconds(mt_rand(0, 8 * 86400));
        $status = 'open';
        $agent = null;
        $team = null;
        $events = [['created', $at, [], ['status' => 'open', 'priority' => 'P3']]];
        $resolvedAt = null;
        for ($step = 0; $step < 8 && $next[$status] !== []; $step++) {
            $at = $at->addSeconds(mt_rand(600, 40 * 3600));
            if ($at->greaterThan('2026-09-20 00:00:00')) {
                break;
            }
            $to = $next[$status][mt_rand(0, count($next[$status]) - 1)];
            if ($to === 'assigned' || mt_rand(0, 4) === 0 && in_array($status, ['in_progress', 'pending'], true)) {
                $newAgent = $agents[mt_rand(0, 1)];
                $newTeam = $teams[mt_rand(0, 1)];
                $events[] = ['assigned', $at, ['status' => $status, 'agent_id' => $agent, 'team_id' => $team],
                    ['status' => $to === 'assigned' ? 'assigned' : $status, 'agent_id' => $newAgent, 'team_id' => $newTeam]];
                [$agent, $team] = [$newAgent, $newTeam];
                $status = $to === 'assigned' ? 'assigned' : $status;

                continue;
            }
            $events[] = [$to === 'in_progress' && $status === 'resolved' ? 'reopened' : 'status_changed', $at, ['status' => $status], ['status' => $to]];
            $resolvedAt = match ($to) {
                'resolved' => $at,
                'closed' => $resolvedAt,
                default => null,
            };
            $status = $to;
        }

        $ticket = Ticket::factory()->forTenant($tenant)->create([
            'status' => TicketStatus::from($status), 'assigned_agent_id' => $agent, 'team_id' => $team,
            'created_at' => $events[0][1], 'resolved_at' => $resolvedAt,
            'closed_at' => $status === 'closed' ? $at : null,
        ]);
        $tenant->run(function () use ($ticket, $events): void {
            foreach ($events as [$type, $when, $old, $new]) {
                TicketEvent::query()->create(['ticket_id' => $ticket->id, 'type' => $type, 'actor_type' => 'system',
                    'actor_id' => null, 'old_values' => $old, 'new_values' => $new, 'note' => null, 'created_at' => $when]);
            }
        });
        $tickets[] = $ticket;
    }

    $tenant->run(function () use ($tickets): void {
        foreach ($tickets as $ticket) {
            app(TicketReportWriter::class)->refresh($ticket->id);
        }
    });

    return $tickets;
}
