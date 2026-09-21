<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Support;

use App\Models\User;

/**
 * Which recorded tables the history API shows, and who may read each (docs/03-architecture/security.md
 * §History). `history.view` (owner, admin, manager) opens every subject the reader could also see
 * today; the ticket family is also open to readers with `tickets.view` and `comments.internal` (agents:
 * "tickets only" in the permission matrix), never to read-only integrations (developer).
 */
final class HistorySubjects
{
    /** entity type (the table) => the permission needed to see such a record at all */
    public const array SUBJECTS = [
        'tickets' => 'tickets.view',
        'ticket_comments' => 'tickets.view',
        'ticket_assignments' => 'tickets.view',
        'ticket_sla_timers' => 'tickets.view',
        'ticket_duplicate_suggestions' => 'tickets.view',
        'sla_events' => 'tickets.view',
        'contacts' => 'contacts.view',
        'organizations' => 'contacts.view',
        'agent_profiles' => 'agents.view',
        'agent_shifts' => 'agents.view',
        'agent_skills' => 'agents.view',
        'teams' => 'agents.view',
        'team_members' => 'agents.view',
        'skills' => 'agents.view',
        'categories' => 'tickets.view',
        'sla_policies' => 'tickets.view',
        'sla_targets' => 'tickets.view',
        'business_calendars' => 'tickets.view',
        'calendar_holidays' => 'tickets.view',
        'media_items' => 'media.view',
        'media_folders' => 'media.view',
        'mediables' => 'media.view',
        'users' => 'users.manage',
        'tenant_settings' => 'settings.manage',
    ];

    private const array TICKET_FAMILY = [
        'tickets', 'ticket_comments', 'ticket_assignments', 'ticket_sla_timers', 'ticket_duplicate_suggestions', 'sla_events',
    ];

    public static function exists(string $type): bool
    {
        return isset(self::SUBJECTS[$type]);
    }

    public static function allows(User $user, string $type): bool
    {
        $needed = self::SUBJECTS[$type] ?? null;
        if ($needed === null || ! $user->can($needed)) {
            return false;
        }

        return $user->can('history.view')
            || (in_array($type, self::TICKET_FAMILY, true) && $user->can('comments.internal'));
    }

    /**
     * Columns never shown in a reconstructed record: whatever capture does not record (their current
     * value would be passed off as a past one) and the row's own bookkeeping.
     *
     * @return list<string>
     */
    public static function hiddenColumns(string $type): array
    {
        return [...ReportableTables::excludedColumns($type), ...ReportableTables::ALWAYS_EXCLUDED];
    }
}
