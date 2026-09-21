<?php

declare(strict_types=1);

use App\Modules\Tenancy\Support\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/*
 * Row-level security on every tenant table (M3-07, ADR-0006, docs/08-database/tenancy.md): ENABLE,
 * FORCE and the `tenant_isolation` policy, generated from TenantTables::all(). The policies read
 * `app.current_tenant`, which RlsTenancyBootstrapper sets when a tenant is initialised and resets
 * when it ends; without it a tenant table shows no rows and refuses inserts.
 *
 * Tables created by later migrations are not in the schema yet when this runs on a fresh database;
 * their own migration calls TenantTables::enableRowLevelSecurity(), and the isolation suite fails
 * for any registry row without the policy.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (TenantTables::all() as $table) {
            if (Schema::hasTable($table)) {
                TenantTables::enableRowLevelSecurity($table);
            }
        }
    }

    public function down(): void
    {
        foreach (TenantTables::all() as $table) {
            if (Schema::hasTable($table)) {
                TenantTables::disableRowLevelSecurity($table);
            }
        }
    }
};
