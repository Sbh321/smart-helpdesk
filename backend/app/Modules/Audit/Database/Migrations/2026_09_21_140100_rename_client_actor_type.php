<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * API clients are recorded as `api_client`, the same actor type the change-capture trigger writes
 * to entity_changes (ADR-0022 §1, docs/04-domain/audit.md). No `client` rows existed before M3-04.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE audit_logs DROP CONSTRAINT audit_logs_actor_type_check');
        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_actor_type_check CHECK (actor_type IN ('user', 'platform_user', 'api_client', 'system'))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE audit_logs DROP CONSTRAINT audit_logs_actor_type_check');
        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_actor_type_check CHECK (actor_type IN ('user', 'platform_user', 'client', 'system'))");
    }
};
