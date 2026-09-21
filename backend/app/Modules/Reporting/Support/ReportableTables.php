<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Support;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Registry of the tables whose every write is captured into `entity_changes`
 * ([ADR-0022](docs/adr/0022-reporting-and-history.md) §1,
 * docs/05-algorithms/history-and-time-analytics.md §2).
 *
 * The migration that creates a reportable table calls `captureChanges()`; the table must be
 * listed here with the columns that are never recorded, because the schema test
 * (tests/Feature/Reporting/ChangeCaptureSchemaTest.php) compares this list with `pg_trigger`.
 * The sibling registry for tenancy is `App\Modules\Tenancy\Support\TenantTables`.
 */
final class ReportableTables
{
    /**
     * Reportable table => columns never written into `changes` (secrets, hashes, tokens, bodies).
     * `updated_at` is excluded for every table by the function itself.
     *
     * Tables of later milestones (tickets, contacts, organizations, teams, …) are added here by
     * their own migration's change.
     *
     * @var array<string, list<string>>
     */
    public const TABLES = [
        'agent_profiles' => [],
        'agent_shifts' => [],
        'agent_skills' => [],
        'business_calendars' => [],
        'calendar_holidays' => [],
        'categories' => [],
        'contacts' => [],
        'media_folders' => [],
        'media_items' => ['storage_key'],
        'mediables' => [],
        'organizations' => [],
        'sla_events' => [],
        'sla_policies' => [],
        'sla_targets' => [],
        'skills' => [],
        'team_members' => [],
        'teams' => [],
        'tenant_settings' => [],
        'ticket_assignments' => [],
        // ADR-0022 records comment metadata only; text bodies are never versioned (history doc §10).
        'ticket_comments' => ['body'],
        'ticket_duplicate_suggestions' => [],
        'ticket_sla_timers' => [],
        // search_vector is derived from title and description, which are recorded themselves.
        'tickets' => ['search_vector'],
        'users' => ['password', 'remember_token'],
    ];

    /** Never recorded, whatever the table: a row's own bookkeeping column. */
    public const ALWAYS_EXCLUDED = ['updated_at'];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::TABLES);
    }

    /**
     * The columns the trigger on `$table` is expected to carry as arguments.
     *
     * @return list<string>
     */
    public static function excludedColumns(string $table): array
    {
        return self::TABLES[$table] ?? throw new LogicException(
            "Table [{$table}] is not in ReportableTables::TABLES; add it with its excluded columns."
        );
    }

    /**
     * Attaches the row-level capture trigger to a reportable table.
     *
     * Called from the migration that creates the table, right after `protectTenantId()`.
     * `$excluded` overrides the registry for a one-off table; leaving it empty (the normal case)
     * uses the registry, which keeps a single source of truth for the schema test.
     *
     * @param  list<string>  $excluded
     */
    public static function captureChanges(string $table, array $excluded = []): void
    {
        $columns = $excluded === [] ? self::excludedColumns($table) : $excluded;

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $arguments = implode(', ', array_map(
            static fn (string $column): string => (string) DB::getPdo()->quote($column),
            $columns,
        ));

        DB::statement(sprintf(
            'CREATE TRIGGER %1$s_changes AFTER INSERT OR UPDATE OR DELETE ON %1$s '
            .'FOR EACH ROW EXECUTE FUNCTION record_entity_change(%2$s)',
            $table,
            $arguments,
        ));
    }
}
