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
        'categories',
        'category_skill',
        'contacts',
        'entity_changes',
        'invitations',
        'model_has_permissions',
        'model_has_roles',
        'organizations',
        'skills',
        'taggables',
        'tags',
        'tenant_settings',
        'ticket_assignments',
        'ticket_comments',
        'ticket_events',
        'tickets',
        'users',
    ];

    /** Tables with a nullable tenant_id (platform-level rows have none). */
    public const NULLABLE = [
        'audit_logs',
        // Global default roles have no tenant; custom roles belong to one workspace.
        'roles',
    ];

    /** Credential tables read before tenancy is initialised: they carry tenant_id but never get RLS. */
    public const CREDENTIALS = [
        'sessions',
        'personal_access_tokens',
    ];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [...self::PRIMARY, ...self::NULLABLE];
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
