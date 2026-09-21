<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for tenant-scoped tables (docs/08-database/tenancy.md).
 *
 * Used by the isolation suite (M1-10), the RLS migration (M3) and table migrations, which call
 * `protectTenantId()` so `tenant_id` can never change after insert.
 * Add every new application-plane table here in the migration's change.
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
