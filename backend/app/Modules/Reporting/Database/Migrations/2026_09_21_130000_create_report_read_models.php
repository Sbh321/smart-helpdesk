<?php

declare(strict_types=1);

use App\Modules\Tenancy\Support\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Derived read models (ADR-0022 §3, docs/08-database/entities.md §Reporting, indexing.md). Rebuilt from
 * history by `reports:rebuild`, so they carry no change-capture trigger. Agent and team ids are plain
 * uuids without a foreign key: history keeps pointing at records that were deleted later.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        Schema::create('report_ticket_intervals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('ticket_id');
            $table->integer('seq');
            $table->string('status', 12);
            $table->uuid('assigned_agent_id')->nullable();
            $table->uuid('team_id')->nullable();
            $table->string('priority_level', 2);
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();
            // Null while the interval is open: durations then run to "now" and are computed at query time.
            $table->bigInteger('wall_seconds')->nullable();
            $table->bigInteger('business_seconds')->nullable();
            $table->unique(['tenant_id', 'ticket_id', 'seq']);
            $table->foreign(['tenant_id', 'ticket_id'])->references(['tenant_id', 'id'])->on('tickets')->cascadeOnDelete();
            $table->index(['tenant_id', 'assigned_agent_id', 'starts_at']);
        });
        DB::statement('CREATE INDEX report_ticket_intervals_span_gist ON report_ticket_intervals USING gist (tenant_id, tstzrange(starts_at, ends_at))');
        DB::statement('CREATE INDEX report_ticket_intervals_current_idx ON report_ticket_intervals (tenant_id, status) WHERE ends_at IS NULL');
        DB::statement('ALTER TABLE report_ticket_intervals ADD CONSTRAINT report_ticket_intervals_order_check CHECK (ends_at IS NULL OR ends_at >= starts_at)');
        TenantTables::protectTenantId('report_ticket_intervals');

        Schema::create('report_ticket_facts', function (Blueprint $table): void {
            // A surrogate key like every other table; the natural key is (tenant_id, ticket_id).
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('ticket_id');
            $table->timestampTz('created_at');
            $table->timestampTz('first_responded_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->string('channel', 8);
            $table->uuid('category_id')->nullable();
            $table->uuid('team_id')->nullable();
            $table->uuid('assigned_agent_id')->nullable();
            $table->uuid('organization_id')->nullable();
            $table->uuid('contact_id')->nullable();
            $table->string('priority_level', 2);
            $table->string('initial_priority_level', 2);
            $table->bigInteger('first_response_wall_s')->nullable();
            $table->bigInteger('first_response_business_s')->nullable();
            $table->bigInteger('resolution_wall_s')->nullable();
            $table->bigInteger('resolution_business_s')->nullable();
            $table->bigInteger('pending_s')->default(0);
            $table->bigInteger('unassigned_s')->default(0);
            $table->integer('reopen_count')->default(0);
            $table->integer('reassign_count')->default(0);
            $table->integer('comment_count')->default(0);
            $table->integer('public_reply_count')->default(0);
            $table->integer('email_in_count')->default(0);
            $table->string('first_response_sla', 24)->nullable();
            $table->string('resolution_sla', 24)->nullable();
            $table->boolean('priority_overridden')->default(false);
            $table->boolean('closed_as_duplicate')->default(false);
            $table->string('priority_strategy', 64)->nullable();
            $table->string('assignment_strategy', 64)->nullable();
            $table->timestampTz('refreshed_at');
            $table->unique(['tenant_id', 'ticket_id']);
            $table->foreign(['tenant_id', 'ticket_id'])->references(['tenant_id', 'id'])->on('tickets')->cascadeOnDelete();
            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'resolved_at']);
            $table->index(['tenant_id', 'team_id', 'created_at']);
            $table->index(['tenant_id', 'assigned_agent_id', 'resolved_at']);
            $table->index(['tenant_id', 'organization_id', 'created_at']);
        });
        foreach (['first_response_sla', 'resolution_sla'] as $column) {
            DB::statement("ALTER TABLE report_ticket_facts ADD CONSTRAINT report_ticket_facts_{$column}_check CHECK ({$column} IN ('met', 'breached', 'running', 'cancelled'))");
        }
        TenantTables::protectTenantId('report_ticket_facts');

        Schema::create('report_daily_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->date('day');
            $table->string('dimension', 16);
            $table->string('dimension_key', 64);
            $table->jsonb('metrics');
            $table->timestampTz('computed_at');
            $table->unique(['tenant_id', 'day', 'dimension', 'dimension_key']);
        });
        DB::statement("ALTER TABLE report_daily_snapshots ADD CONSTRAINT report_daily_snapshots_dimension_check CHECK (dimension IN ('none', 'team', 'agent', 'priority', 'category', 'status'))");
        TenantTables::protectTenantId('report_daily_snapshots');
    }

    public function down(): void
    {
        Schema::dropIfExists('report_daily_snapshots');
        Schema::dropIfExists('report_ticket_facts');
        Schema::dropIfExists('report_ticket_intervals');
    }
};
