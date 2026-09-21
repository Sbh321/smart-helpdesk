<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for tenant-scoped tables (docs/08-database/tenancy.md).
 *
 * Used by the isolation suite (M1-10), the RLS migration (M3-07) and table migrations, which call
 * `protectTenantId()` so `tenant_id` can never change after insert.
 *
 * Adding an application-plane table: list it here in the same change, and in its create migration
 * call `protectTenantId()` and `enableRowLevelSecurity()` after the table exists. The M3-07 migration
 * only covers the tables that existed when it ran; `tests/Isolation/RowLevelSecurityTest.php` fails
 * for any row of `all()` without the policy (docs/08-database/tenancy.md §Row-level security).
 *
 * Change capture has its own registry, `App\Modules\Reporting\Support\ReportableTables`
 * (which tables are reportable and which columns are never recorded, ADR-0022).
 */
final class TenantTables
{
    /** Tables whose rows always belong to one tenant. */
    public const PRIMARY = [
        'agent_profiles',
        'agent_shifts',
        'agent_skills',
        'business_calendars',
        'calendar_holidays',
        'categories',
        'category_skill',
        'contacts',
        'entity_changes',
        'idempotency_keys',
        'invitations',
        'media_folders',
        'media_items',
        'mediables',
        'model_has_permissions',
        'model_has_roles',
        'notifications',
        'organizations',
        'report_daily_snapshots',
        'report_exports',
        'report_ticket_facts',
        'report_ticket_intervals',
        'sla_events',
        'sla_policies',
        'sla_targets',
        'skills',
        'team_members',
        'teams',
        'taggables',
        'tags',
        'tenant_settings',
        'ticket_assignments',
        'ticket_comments',
        'ticket_duplicate_suggestions',
        'ticket_events',
        'ticket_sla_timers',
        'tickets',
        'users',
        'webhook_deliveries',
        'webhook_subscriptions',
    ];

    /** Tables with a nullable tenant_id (platform-level rows have none). */
    public const NULLABLE = [
        'audit_logs',
        // Global default roles have no tenant; custom roles belong to one workspace.
        'roles',
    ];

    /**
     * Credential tables read before tenancy is initialised: they carry tenant_id but never get RLS.
     * The OAuth tables are read by the token endpoint and the tenant resolver (M3-04); their
     * tenant_id is NOT NULL because a client always belongs to one workspace.
     */
    public const CREDENTIALS = [
        'sessions',
        'personal_access_tokens',
        'oauth_clients',
        'oauth_access_tokens',
    ];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [...self::PRIMARY, ...self::NULLABLE];
    }

    /**
     * The tenant of the current PostgreSQL session as the policies read it. `RESET` leaves a custom
     * setting as '' rather than NULL once it has been set on a connection, hence the NULLIF; unset
     * or reset, the expression is NULL and a `tenant_id = NULL` predicate matches no row.
     */
    public const CURRENT_TENANT_SQL = "NULLIF(current_setting('app.current_tenant', true), '')::uuid";

    /** The policy every tenant table carries; the isolation suite looks for this name. */
    public const POLICY = 'tenant_isolation';

    /** Extra read policy on `roles`: the global default roles are visible inside every tenant. */
    public const GLOBAL_ROLES_POLICY = 'global_roles_read';

    /**
     * Enables and forces row-level security on a tenant table with the `tenant_isolation` policy
     * (docs/08-database/tenancy.md §Row-level security). Idempotent, so a migration may call it for
     * a table the M3-07 migration already covered. FORCE makes the policy apply to the table owner
     * too, so no connection of the application, not even `pgsql_owner`, reads across tenants.
     *
     * NOT NULL tables: rows of the session tenant only; with no tenant set, nothing.
     * Nullable tables (`audit_logs`, `roles`): `IS NOT DISTINCT FROM`, so a tenant sees its rows and
     * the central context (no tenant) sees and writes only the platform rows with a NULL tenant.
     * `roles` adds a read-only policy for the global default roles.
     */
    public static function enableRowLevelSecurity(string $table): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $tenant = self::CURRENT_TENANT_SQL;
        $predicate = in_array($table, self::NULLABLE, true)
            ? "tenant_id IS NOT DISTINCT FROM {$tenant}"
            : "tenant_id = {$tenant}";

        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement(sprintf('DROP POLICY IF EXISTS %s ON %s', self::POLICY, $table));
        DB::statement(sprintf('CREATE POLICY %s ON %s USING (%s) WITH CHECK (%s)', self::POLICY, $table, $predicate, $predicate));

        if ($table === 'roles') {
            DB::statement(sprintf('DROP POLICY IF EXISTS %s ON roles', self::GLOBAL_ROLES_POLICY));
            DB::statement(sprintf('CREATE POLICY %s ON roles FOR SELECT USING (tenant_id IS NULL)', self::GLOBAL_ROLES_POLICY));
        }
    }

    /**
     * Reverse of `enableRowLevelSecurity()` for a migration's `down()`.
     */
    public static function disableRowLevelSecurity(string $table): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(sprintf('DROP POLICY IF EXISTS %s ON %s', self::POLICY, $table));
        DB::statement(sprintf('DROP POLICY IF EXISTS %s ON %s', self::GLOBAL_ROLES_POLICY, $table));
        DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
    }

    /**
     * Composite foreign key whose delete action clears only the relation column.
     *
     * Plain `ON DELETE SET NULL` on (tenant_id, x_id) nulls both columns, and tenant_id is NOT NULL
     * and immutable, so the parent delete fails. PostgreSQL 15+ takes a column list.
     */
    public static function nullableForeign(string $table, string $column, string $references, ?string $name = null): void
    {
        DB::statement(sprintf(
            'ALTER TABLE %1$s ADD CONSTRAINT %4$s FOREIGN KEY (tenant_id, %2$s) REFERENCES %3$s (tenant_id, id) ON DELETE SET NULL (%2$s)',
            $table, $column, $references, $name ?? "{$table}_{$column}_fk",
        ));
    }

    /**
     * Attaches the shared BEFORE UPDATE trigger that rejects tenant_id changes.
     */
    public static function protectTenantId(string $table): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(sprintf(
            'CREATE TRIGGER %1$s_tenant_id_immutable BEFORE UPDATE OF tenant_id ON %1$s FOR EACH ROW EXECUTE FUNCTION prevent_tenant_id_change()',
            $table,
        ));
    }
}
