<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Overviews;

use App\Support\Time\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The 360 numbers of one record (docs/04-domain/reporting.md §Entity 360): key metrics, related records
 * and trends, read from the report read models. Must run inside the workspace; every query is scoped to
 * it. Windows are the last 30 days (workload, performance) or 12 weeks (trends), ending now.
 */
final readonly class EntityOverviews
{
    public const int TREND_WEEKS = 12;

    public function __construct(private Clock $clock) {}

    /** @return array<string, mixed>|null null when the ticket is not in this workspace */
    public function ticket(string $id): ?array
    {
        $ticket = DB::table('tickets')->where('tenant_id', $this->tenant())->where('id', $id)
            ->first(['id', 'number', 'title', 'status', 'created_at']);
        if ($ticket === null) {
            return null;
        }
        $now = $this->clock->now();

        $trace = DB::table('report_ticket_intervals')->where('tenant_id', $this->tenant())->where('ticket_id', $id)->orderBy('seq')
            ->get(['seq', 'status', 'assigned_agent_id', 'team_id', 'priority_level', 'starts_at', 'ends_at', 'wall_seconds', 'business_seconds'])
            ->map(fn (object $row): array => [
                'seq' => (int) $row->seq,
                'status' => $row->status,
                'assigned_agent_id' => $row->assigned_agent_id,
                'team_id' => $row->team_id,
                'priority_level' => $row->priority_level,
                'starts_at' => $this->iso($row->starts_at),
                'ends_at' => $row->ends_at === null ? null : $this->iso($row->ends_at),
                // The open interval runs to now; its business time is not stored.
                'wall_seconds' => $row->wall_seconds === null
                    ? max(0, $now->getTimestamp() - CarbonImmutable::parse($row->starts_at)->getTimestamp())
                    : (int) $row->wall_seconds,
                'business_seconds' => $row->business_seconds === null ? null : (int) $row->business_seconds,
                'open' => $row->ends_at === null,
            ])->all();

        $timers = DB::table('ticket_sla_timers')->where('tenant_id', $this->tenant())->where('ticket_id', $id)
            ->orderBy('cycle')->orderBy('kind')->get(['kind', 'cycle', 'state', 'due_at', 'met_at', 'breached_at'])
            ->map(fn (object $row): array => [
                'kind' => $row->kind, 'cycle' => (int) $row->cycle, 'state' => $row->state,
                'due_at' => $this->iso($row->due_at),
                'met_at' => $row->met_at === null ? null : $this->iso($row->met_at),
                'breached_at' => $row->breached_at === null ? null : $this->iso($row->breached_at),
            ])->all();

        $fact = DB::table('report_ticket_facts')->where('tenant_id', $this->tenant())->where('ticket_id', $id)->first();

        return [
            'entity' => 'tickets',
            'id' => $ticket->id,
            'title' => "#{$ticket->number} {$ticket->title}",
            'metrics' => [
                'first_response_seconds' => $this->int($fact->first_response_business_s ?? null),
                'resolution_seconds' => $this->int($fact->resolution_business_s ?? null),
                'pending_seconds' => $this->int($fact->pending_s ?? null),
                'unassigned_seconds' => $this->int($fact->unassigned_s ?? null),
                'reassignments' => $this->int($fact->reassign_count ?? null),
                'reopens' => $this->int($fact->reopen_count ?? null),
                'comments' => $this->int($fact->comment_count ?? null),
                'public_replies' => $this->int($fact->public_reply_count ?? null),
                'first_response_sla' => $fact->first_response_sla ?? null,
                'resolution_sla' => $fact->resolution_sla ?? null,
            ],
            'related' => ['sla_timers' => $timers],
            'trends' => ['lifecycle' => $trace],
        ];
    }

    /** @return array<string, mixed>|null */
    public function contact(string $id): ?array
    {
        $contact = DB::table('contacts')->where('tenant_id', $this->tenant())->where('id', $id)->first(['id', 'name', 'email', 'organization_id']);
        if ($contact === null) {
            return null;
        }

        return [
            'entity' => 'contacts',
            'id' => $contact->id,
            'title' => $contact->name,
            'metrics' => $this->ticketMetrics('f.contact_id = ?', [$id]),
            'related' => [
                'organization_id' => $contact->organization_id,
                'recent_tickets' => $this->recentTickets('contact_id', $id),
            ],
            'trends' => ['tickets_per_week' => $this->weeklyTickets('f.contact_id = ?', [$id])],
        ];
    }

    /** @return array<string, mixed>|null */
    public function organization(string $id): ?array
    {
        $organization = DB::table('organizations')->where('tenant_id', $this->tenant())->where('id', $id)->first(['id', 'name', 'tier']);
        if ($organization === null) {
            return null;
        }

        $tierHistory = DB::table('entity_changes')->where('tenant_id', $this->tenant())
            ->where('entity_type', 'organizations')->where('entity_id', $id)
            ->whereRaw("jsonb_exists(changes, 'tier')")->orderBy('version')
            ->get(['occurred_at', 'changes'])
            ->map(function (object $row): array {
                $tier = (array) (json_decode((string) $row->changes, true)['tier'] ?? []);

                return ['at' => $this->iso($row->occurred_at), 'from' => $tier['old'] ?? null, 'to' => $tier['new'] ?? null];
            })->all();

        $topCategories = DB::select(<<<'SQL'
            SELECT f.category_id AS id, c.name, count(*) AS tickets
            FROM report_ticket_facts f LEFT JOIN categories c ON c.tenant_id = f.tenant_id AND c.id = f.category_id
            WHERE f.tenant_id = ? AND f.organization_id = ?
            GROUP BY 1, 2 ORDER BY 3 DESC, 2 LIMIT 5
            SQL, [$this->tenant(), $id]);

        return [
            'entity' => 'organizations',
            'id' => $organization->id,
            'title' => $organization->name,
            'metrics' => [
                'tier' => $organization->tier,
                'contacts' => DB::table('contacts')->where('tenant_id', $this->tenant())->where('organization_id', $id)->count(),
                ...$this->ticketMetrics('f.organization_id = ?', [$id]),
            ],
            'related' => [
                'top_categories' => array_map(fn (object $row): array => ['id' => $row->id, 'name' => $row->name, 'tickets' => (int) $row->tickets], $topCategories),
                'tier_history' => $tierHistory,
            ],
            'trends' => ['tickets_per_week' => $this->weeklyTickets('f.organization_id = ?', [$id])],
        ];
    }

    /** @return array<string, mixed>|null */
    public function agent(string $id): ?array
    {
        $agent = DB::table('agent_profiles as a')->join('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.tenant_id', $this->tenant())->where('a.id', $id)
            ->first(['a.id', 'u.name', 'a.capacity', 'a.availability', 'a.active_ticket_count']);
        if ($agent === null) {
            return null;
        }
        $since = $this->clock->now()->subDays(30);

        return [
            'entity' => 'agents',
            'id' => $agent->id,
            'title' => $agent->name,
            'metrics' => [
                'capacity' => (int) $agent->capacity,
                'availability' => $agent->availability,
                'open_tickets' => (int) $agent->active_ticket_count,
                ...$this->performance('f.assigned_agent_id = ?', [$id], $since),
            ],
            'related' => [
                'skills' => DB::table('agent_skills as s')->join('skills as k', 'k.id', '=', 's.skill_id')
                    ->where('s.tenant_id', $this->tenant())->where('s.agent_profile_id', $id)->orderBy('k.name')
                    ->get(['k.id', 'k.name', 's.level'])->map(fn (object $row): array => ['id' => $row->id, 'name' => $row->name, 'level' => (int) $row->level])->all(),
                'teams' => DB::table('team_members as m')->join('teams as t', 't.id', '=', 'm.team_id')
                    ->where('m.tenant_id', $this->tenant())->where('m.agent_profile_id', $id)->orderBy('t.name')
                    ->get(['t.id', 't.name'])->map(fn (object $row): array => ['id' => $row->id, 'name' => $row->name])->all(),
            ],
            'trends' => ['backlog_per_day' => $this->snapshotTrend('agent', $id)],
        ];
    }

    /** @return array<string, mixed>|null */
    public function team(string $id): ?array
    {
        $team = DB::table('teams')->where('tenant_id', $this->tenant())->where('id', $id)->first(['id', 'name']);
        if ($team === null) {
            return null;
        }

        return [
            'entity' => 'teams',
            'id' => $team->id,
            'title' => $team->name,
            'metrics' => $this->performance('f.team_id = ?', [$id], $this->clock->now()->subDays(30)),
            'related' => [
                'members' => DB::table('team_members as m')->join('agent_profiles as a', 'a.id', '=', 'm.agent_profile_id')
                    ->join('users as u', 'u.id', '=', 'a.user_id')->where('m.tenant_id', $this->tenant())->where('m.team_id', $id)
                    ->orderBy('u.name')->get(['a.id', 'u.name', 'm.joined_at'])
                    ->map(fn (object $row): array => ['id' => $row->id, 'name' => $row->name, 'joined_at' => $this->iso($row->joined_at)])->all(),
            ],
            'trends' => ['backlog_per_day' => $this->snapshotTrend('team', $id)],
        ];
    }

    /** @return array<string, mixed>|null */
    public function category(string $id): ?array
    {
        $category = DB::table('categories')->where('tenant_id', $this->tenant())->where('id', $id)->first(['id', 'name']);
        if ($category === null) {
            return null;
        }

        $topAgents = DB::select(<<<'SQL'
            SELECT f.assigned_agent_id AS id, u.name, count(*) AS resolved
            FROM report_ticket_facts f
            JOIN agent_profiles a ON a.tenant_id = f.tenant_id AND a.id = f.assigned_agent_id
            JOIN users u ON u.id = a.user_id
            WHERE f.tenant_id = ? AND f.category_id = ? AND f.resolved_at IS NOT NULL
            GROUP BY 1, 2 ORDER BY 3 DESC, 2 LIMIT 5
            SQL, [$this->tenant(), $id]);

        return [
            'entity' => 'categories',
            'id' => $category->id,
            'title' => $category->name,
            'metrics' => $this->ticketMetrics('f.category_id = ?', [$id]),
            'related' => [
                'required_skills' => DB::table('category_skill as c')->join('skills as k', 'k.id', '=', 'c.skill_id')
                    ->where('c.tenant_id', $this->tenant())->where('c.category_id', $id)->orderBy('k.name')
                    ->get(['k.id', 'k.name'])->map(fn (object $row): array => ['id' => $row->id, 'name' => $row->name])->all(),
                'top_agents' => array_map(fn (object $row): array => ['id' => $row->id, 'name' => $row->name, 'resolved' => (int) $row->resolved], $topAgents),
            ],
            'trends' => ['tickets_per_week' => $this->weeklyTickets('f.category_id = ?', [$id])],
        ];
    }

    /**
     * Tickets ever raised, open now, reopen rate, breaches, median first response and resolution.
     *
     * @param  list<string>  $bindings
     * @return array<string, int|float|null>
     */
    private function ticketMetrics(string $condition, array $bindings): array
    {
        $row = DB::selectOne(<<<SQL
            SELECT count(*) AS tickets,
                   count(*) FILTER (WHERE f.resolved_at IS NULL AND f.closed_at IS NULL) AS open_tickets,
                   round(100.0 * count(*) FILTER (WHERE f.reopen_count > 0) / nullif(count(*) FILTER (WHERE f.resolved_at IS NOT NULL OR f.reopen_count > 0), 0), 1) AS reopen_rate,
                   count(*) FILTER (WHERE f.first_response_sla = 'breached' OR f.resolution_sla = 'breached') AS breached,
                   round(100.0 * count(*) FILTER (WHERE f.resolution_sla = 'met') / nullif(count(*) FILTER (WHERE f.resolution_sla IN ('met', 'breached')), 0), 1) AS sla_compliance,
                   percentile_cont(0.5) WITHIN GROUP (ORDER BY f.first_response_business_s) AS first_response_median,
                   percentile_cont(0.5) WITHIN GROUP (ORDER BY f.resolution_business_s) AS resolution_median
            FROM report_ticket_facts f WHERE f.tenant_id = ? AND {$condition}
            SQL, [$this->tenant(), ...$bindings]);

        return [
            'tickets' => (int) $row->tickets,
            'open_tickets' => (int) $row->open_tickets,
            'reopen_rate' => $row->reopen_rate === null ? null : (float) $row->reopen_rate,
            'breached' => (int) $row->breached,
            'sla_compliance' => $row->sla_compliance === null ? null : (float) $row->sla_compliance,
            'first_response_median_seconds' => $row->first_response_median === null ? null : (float) $row->first_response_median,
            'resolution_median_seconds' => $row->resolution_median === null ? null : (float) $row->resolution_median,
        ];
    }

    /**
     * The last 30 days of work: assigned (created), resolved, first replies, median resolution, SLA
     * compliance and reopen rate.
     *
     * @param  list<string>  $bindings
     * @return array<string, int|float|null>
     */
    private function performance(string $condition, array $bindings, CarbonImmutable $since): array
    {
        $row = DB::selectOne(<<<SQL
            SELECT count(*) FILTER (WHERE f.created_at >= ?) AS assigned,
                   count(*) FILTER (WHERE f.resolved_at >= ?) AS resolved,
                   count(*) FILTER (WHERE f.first_responded_at >= ?) AS first_replies,
                   percentile_cont(0.5) WITHIN GROUP (ORDER BY f.resolution_business_s) FILTER (WHERE f.resolved_at >= ?) AS resolution_median,
                   round(100.0 * count(*) FILTER (WHERE f.resolved_at >= ? AND f.resolution_sla = 'met')
                        / nullif(count(*) FILTER (WHERE f.resolved_at >= ? AND f.resolution_sla IN ('met', 'breached')), 0), 1) AS sla_compliance,
                   round(100.0 * count(*) FILTER (WHERE f.resolved_at >= ? AND f.reopen_count > 0)
                        / nullif(count(*) FILTER (WHERE f.resolved_at >= ?), 0), 1) AS reopen_rate
            FROM report_ticket_facts f WHERE f.tenant_id = ? AND {$condition}
            SQL, [$since, $since, $since, $since, $since, $since, $since, $since, $this->tenant(), ...$bindings]);

        return [
            'assigned_30d' => (int) $row->assigned,
            'resolved_30d' => (int) $row->resolved,
            'first_replies_30d' => (int) $row->first_replies,
            'resolution_median_seconds_30d' => $row->resolution_median === null ? null : (float) $row->resolution_median,
            'sla_compliance_30d' => $row->sla_compliance === null ? null : (float) $row->sla_compliance,
            'reopen_rate_30d' => $row->reopen_rate === null ? null : (float) $row->reopen_rate,
        ];
    }

    /**
     * Tickets created per week (workspace time zone) over the last 12 weeks, zero-filled.
     *
     * @param  list<string>  $bindings
     * @return list<array{week: string, tickets: int}>
     */
    private function weeklyTickets(string $condition, array $bindings): array
    {
        $timezone = (string) (tenant('timezone') ?: 'UTC');
        $thisWeek = $this->clock->now()->setTimezone($timezone)->startOfWeek();
        $first = $thisWeek->subWeeks(self::TREND_WEEKS - 1);
        $counts = collect(DB::select(<<<SQL
            SELECT to_char(date_trunc('week', f.created_at AT TIME ZONE ?), 'YYYY-MM-DD') AS week, count(*) AS n
            FROM report_ticket_facts f WHERE f.tenant_id = ? AND f.created_at >= ? AND {$condition}
            GROUP BY 1
            SQL, [$timezone, $this->tenant(), $first->utc(), ...$bindings]))->pluck('n', 'week');

        $weeks = [];
        for ($week = $first; $week <= $thisWeek; $week = $week->addWeek()) {
            $weeks[] = ['week' => $week->toDateString(), 'tickets' => (int) ($counts[$week->toDateString()] ?? 0)];
        }

        return $weeks;
    }

    /** @return list<array{day: string, backlog: int}> the last 30 snapshot days of one dimension value */
    private function snapshotTrend(string $dimension, string $key): array
    {
        return DB::table('report_daily_snapshots')->where('tenant_id', $this->tenant())
            ->where('dimension', $dimension)->where('dimension_key', $key)
            ->where('day', '>=', $this->clock->now()->subDays(30)->toDateString())->orderBy('day')
            ->get(['day', 'metrics'])
            ->map(fn (object $row): array => ['day' => (string) $row->day, 'backlog' => (int) (json_decode((string) $row->metrics, true)['backlog'] ?? 0)])
            ->all();
    }

    /** @return list<array{id: string, number: int, title: string, status: string, created_at: string}> */
    private function recentTickets(string $column, string $id): array
    {
        return DB::table('tickets')->where('tenant_id', $this->tenant())->where($column, $id)
            ->orderByDesc('created_at')->limit(10)->get(['id', 'number', 'title', 'status', 'created_at'])
            ->map(fn (object $row): array => ['id' => $row->id, 'number' => (int) $row->number, 'title' => $row->title, 'status' => $row->status, 'created_at' => $this->iso($row->created_at)])
            ->all();
    }

    private function tenant(): string
    {
        return (string) tenant()?->getTenantKey();
    }

    private function iso(mixed $value): string
    {
        return CarbonImmutable::parse((string) $value)->toIso8601ZuluString();
    }

    private function int(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
