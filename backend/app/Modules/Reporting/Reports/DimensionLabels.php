<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports;

use Illuminate\Support\Facades\DB;

/**
 * Display names for dimension keys (ids of teams, agents, categories, …). Reads the current names, so a
 * renamed team shows its new name for the whole period. Must run inside the workspace.
 */
final class DimensionLabels
{
    /** lookup => [table, name expression, id column] */
    private const array LOOKUPS = [
        'teams' => ['teams', 'name', 'id'],
        'categories' => ['categories', 'name', 'id'],
        'organizations' => ['organizations', 'name', 'id'],
        'contacts' => ['contacts', 'name', 'id'],
        'users' => ['users', 'name', 'id'],
        'skills' => ['skills', 'name', 'id'],
        'sla_policies' => ['sla_policies', 'name', 'id'],
        'media_folders' => ['media_folders', 'name', 'id'],
        'calendars' => ['business_calendars', 'name', 'id'],
    ];

    private const array FIXED = [
        'status' => ['open' => 'Open', 'assigned' => 'Assigned', 'in_progress' => 'In progress', 'pending' => 'Pending', 'resolved' => 'Resolved', 'closed' => 'Closed'],
        'priority' => ['P1' => 'P1 Critical', 'P2' => 'P2 High', 'P3' => 'P3 Normal', 'P4' => 'P4 Low'],
        'channel' => ['ui' => 'Agent (UI)', 'api' => 'API', 'email' => 'Email', 'seed' => 'Seeded'],
        'weekday' => self::WEEKDAYS,
        'tier' => ['standard' => 'Standard', 'premium' => 'Premium', 'enterprise' => 'Enterprise'],
        'age_bucket' => ['1' => 'Under 1 day', '2' => '1–3 days', '3' => '3–7 days', '4' => '7–30 days', '5' => 'Over 30 days'],
        'assignment_reason' => ['auto' => 'Automatic', 'manual' => 'Manual', 'reassign' => 'Reassignment', 'unassign' => 'Unassignment'],
        'assignment_outcome' => ['assigned' => 'Assigned', 'team_routed' => 'Routed to a team', 'no_eligible_agent' => 'No eligible agent', 'unassigned' => 'Unassigned'],
        'timer_kind' => ['first_response' => 'First response', 'resolution' => 'Resolution'],
        'breach_cause' => ['first_response' => 'No first response in time', 'resolution' => 'Late resolution'],
        'duplicate_decision' => ['pending' => 'Pending', 'accepted' => 'Accepted', 'dismissed' => 'Dismissed'],
        'actor_type' => ['user' => 'User', 'api_client' => 'API client', 'system' => 'System', 'email' => 'Email', 'platform' => 'Platform'],
        'operation' => ['insert' => 'Created', 'update' => 'Changed', 'delete' => 'Deleted'],
        // Inbound email (RPT-E01, M3-19).
        'inbound_state' => ['comment' => 'Reply added', 'ticket' => 'Ticket created', 'ignored' => 'Ignored', 'unrouted' => 'Not routed', 'rejected' => 'Rejected'],
        'inbound_route' => ['plus_address' => 'Reply address', 'thread' => 'Thread headers', 'intake' => 'Intake address'],
        'inbound_reason' => [
            'auto_reply' => 'Automatic reply', 'bounce' => 'Bounce', 'empty_reply' => 'Nothing new', 'sender_not_allowed' => 'Sender not allowed',
            'tenant_mismatch' => 'Another workspace', 'unknown_sender' => 'Unknown sender', 'sender_archived' => 'Archived contact',
            'no_category' => 'No category', 'too_large' => 'Too large', 'reopen_window_expired' => 'Follow-up (window over)',
            'closed_as_duplicate' => 'Follow-up (duplicate)',
        ],
        'entity_type' => [
            'sla_policies' => 'SLA policies', 'sla_targets' => 'SLA targets', 'business_calendars' => 'Business calendars',
            'calendar_holidays' => 'Calendar holidays', 'tenant_settings' => 'Workspace settings', 'categories' => 'Categories', 'skills' => 'Skills',
        ],
    ];

    private const array WEEKDAYS = ['1' => 'Monday', '2' => 'Tuesday', '3' => 'Wednesday', '4' => 'Thursday', '5' => 'Friday', '6' => 'Saturday', '7' => 'Sunday'];

    /**
     * @param  list<string>  $keys
     * @return array<int|string, string> keyed by the dimension key (numeric keys become ints)
     */
    public function for(?string $lookup, array $keys): array
    {
        $labels = ['-' => 'None'];
        if ($lookup === null) {
            return $labels;
        }
        if (isset(self::FIXED[$lookup])) {
            return [...$labels, ...self::FIXED[$lookup]];
        }
        if ($lookup === 'transition') {
            // `open → assigned` becomes `Open → Assigned`.
            foreach ($keys as $key) {
                $labels[$key] = implode(' → ', array_map(fn (string $status): string => self::FIXED['status'][$status] ?? $status, explode(' → ', $key)));
            }

            return $labels;
        }
        if ($lookup === 'weekday_hour') {
            // `1-09` (ISO weekday, local hour) becomes `Monday 09:00`.
            foreach ($keys as $key) {
                [$day, $hour] = array_pad(explode('-', $key, 2), 2, '');
                $labels[$key] = (self::WEEKDAYS[$day] ?? $day)." {$hour}:00";
            }

            return $labels;
        }

        $ids = array_values(array_filter($keys, fn (string $key): bool => preg_match('/^[0-9a-f-]{36}$/', $key) === 1));
        if ($ids === []) {
            return $labels;
        }
        if ($lookup === 'agents') {
            $names = DB::table('agent_profiles as a')->join('users as u', 'u.id', '=', 'a.user_id')
                ->where('a.tenant_id', tenant()?->getTenantKey())->whereIn('a.id', $ids)->pluck('u.name', 'a.id');
        } elseif ($lookup === 'tickets') {
            $names = DB::table('tickets')->where('tenant_id', tenant()?->getTenantKey())->whereIn('id', $ids)
                ->selectRaw("id, '#' || number || ' ' || title AS name")->pluck('name', 'id');
        } else {
            [$table, $name, $id] = self::LOOKUPS[$lookup];
            $names = DB::table($table)->where('tenant_id', tenant()?->getTenantKey())->whereIn($id, $ids)->pluck($name, $id);
        }

        foreach ($names as $key => $name) {
            $labels[(string) $key] = (string) $name;
        }

        return $labels;
    }
}
