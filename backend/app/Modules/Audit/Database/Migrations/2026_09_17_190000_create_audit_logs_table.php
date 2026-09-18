<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Security audit log (docs/04-domain/audit.md, docs/08-database/entities.md §audit_logs).
 * Append-only: the runtime role may only SELECT and INSERT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // null = platform-level action
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('actor_type', 16);
            $table->uuid('actor_id')->nullable();
            $table->string('action', 48);
            $table->string('subject_type', 64)->nullable();
            $table->uuid('subject_id')->nullable();
            $table->jsonb('changes')->default('{}');
            $table->ipAddress()->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at', 'id'], 'audit_logs_tenant_created_idx');
            $table->index(['tenant_id', 'subject_type', 'subject_id'], 'audit_logs_subject_idx');
            $table->index(['tenant_id', 'actor_id', 'created_at'], 'audit_logs_actor_idx');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_actor_type_check CHECK (actor_type IN ('user', 'platform_user', 'client', 'system'))");
        DB::statement('DROP INDEX audit_logs_tenant_created_idx');
        DB::statement('CREATE INDEX audit_logs_tenant_created_idx ON audit_logs (tenant_id, created_at DESC, id)');
        DB::statement('DROP INDEX audit_logs_actor_idx');
        DB::statement('CREATE INDEX audit_logs_actor_idx ON audit_logs (tenant_id, actor_id, created_at DESC)');

        $runtimeRole = (string) config('database.connections.pgsql.username');
        $roleExists = DB::selectOne('SELECT 1 AS present FROM pg_roles WHERE rolname = ? AND rolname <> current_user', [$runtimeRole]);

        if ($roleExists !== null) {
            DB::statement(sprintf('REVOKE UPDATE, DELETE, TRUNCATE ON audit_logs FROM %s', DB::getQueryGrammar()->wrap($runtimeRole)));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
