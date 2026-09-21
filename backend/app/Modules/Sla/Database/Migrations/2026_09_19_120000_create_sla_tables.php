<?php

declare(strict_types=1);

use App\Modules\Reporting\Support\ReportableTables;
use App\Modules\Tenancy\Support\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_calendars', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('timezone', 64);
            $table->jsonb('weekly_hours');
            $table->boolean('is_default')->default(false);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'name']);
        });
        DB::statement('CREATE UNIQUE INDEX business_calendars_one_default ON business_calendars (tenant_id) WHERE is_default');

        Schema::create('calendar_holidays', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('calendar_id');
            $table->date('date');
            $table->string('name', 120);
            $table->boolean('recurs_yearly')->default(false);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'calendar_id', 'date']);
            $table->foreign(['tenant_id', 'calendar_id'])->references(['tenant_id', 'id'])->on('business_calendars')->cascadeOnDelete();
        });

        Schema::create('sla_policies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 80);
            $table->boolean('is_default')->default(false);
            $table->string('applies_to_tier', 16)->nullable();
            $table->decimal('warning_fraction', 3, 2)->default(0.75);
            $table->uuid('calendar_id')->nullable();
            $table->integer('version')->default(1);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'name']);
            $table->foreign(['tenant_id', 'calendar_id'])->references(['tenant_id', 'id'])->on('business_calendars')->restrictOnDelete();
        });
        DB::statement('CREATE UNIQUE INDEX sla_policies_one_default ON sla_policies (tenant_id) WHERE is_default');
        DB::statement("ALTER TABLE sla_policies ADD CONSTRAINT sla_policies_tier_check CHECK (applies_to_tier IS NULL OR applies_to_tier IN ('standard', 'premium', 'enterprise'))");
        DB::statement('ALTER TABLE sla_policies ADD CONSTRAINT sla_policies_warning_check CHECK (warning_fraction BETWEEN 0.10 AND 0.95)');
        DB::statement('ALTER TABLE sla_policies ADD CONSTRAINT sla_policies_version_check CHECK (version > 0)');

        Schema::create('sla_targets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('policy_id');
            $table->string('priority_level', 2);
            $table->integer('first_response_minutes');
            $table->integer('resolution_minutes');
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'policy_id', 'priority_level']);
            $table->foreign(['tenant_id', 'policy_id'])->references(['tenant_id', 'id'])->on('sla_policies')->cascadeOnDelete();
        });
        DB::statement("ALTER TABLE sla_targets ADD CONSTRAINT sla_targets_priority_check CHECK (priority_level IN ('P1', 'P2', 'P3', 'P4'))");
        DB::statement('ALTER TABLE sla_targets ADD CONSTRAINT sla_targets_minutes_check CHECK (first_response_minutes > 0 AND resolution_minutes > 0)');

        Schema::create('ticket_sla_timers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('ticket_id');
            $table->uuid('policy_id');
            $table->integer('policy_version');
            $table->string('kind', 16);
            $table->smallInteger('cycle')->default(1);
            $table->string('state', 10);
            $table->string('paused_from_state', 10)->nullable();
            $table->integer('target_minutes');
            $table->timestampTz('started_at');
            $table->timestampTz('warning_at');
            $table->timestampTz('due_at');
            $table->timestampTz('paused_at')->nullable();
            $table->integer('paused_total_seconds')->default(0);
            $table->timestampTz('warned_at')->nullable();
            $table->timestampTz('breached_at')->nullable();
            $table->timestampTz('met_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->uuid('calendar_id')->nullable();
            $table->string('strategy', 64);
            $table->string('strategy_version', 24);
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'ticket_id', 'kind', 'cycle'], 'ticket_sla_timers_tenant_ticket_kind_cycle_key');
            $table->foreign(['tenant_id', 'ticket_id'])->references(['tenant_id', 'id'])->on('tickets')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'policy_id'])->references(['tenant_id', 'id'])->on('sla_policies')->restrictOnDelete();
            $table->foreign(['tenant_id', 'calendar_id'])->references(['tenant_id', 'id'])->on('business_calendars')->restrictOnDelete();
            $table->index(['tenant_id', 'kind', 'state'], 'ticket_sla_timers_tenant_state_idx');
        });
        DB::statement("ALTER TABLE ticket_sla_timers ADD CONSTRAINT ticket_sla_timers_kind_check CHECK (kind IN ('first_response', 'resolution'))");
        DB::statement("ALTER TABLE ticket_sla_timers ADD CONSTRAINT ticket_sla_timers_state_check CHECK (state IN ('running', 'paused', 'warning', 'breached', 'met', 'cancelled'))");
        DB::statement("ALTER TABLE ticket_sla_timers ADD CONSTRAINT ticket_sla_timers_paused_from_check CHECK (paused_from_state IS NULL OR paused_from_state IN ('running', 'warning'))");
        DB::statement('ALTER TABLE ticket_sla_timers ADD CONSTRAINT ticket_sla_timers_target_check CHECK (target_minutes > 0 AND cycle > 0 AND paused_total_seconds >= 0)');
        // A ticket has at most one unfinished timer per kind; a reopen finishes the old cycle first.
        DB::statement("CREATE UNIQUE INDEX ticket_sla_timers_one_unfinished_key ON ticket_sla_timers (tenant_id, ticket_id, kind) WHERE state NOT IN ('met', 'cancelled')");
        DB::statement("CREATE INDEX ticket_sla_timers_due_pidx ON ticket_sla_timers (due_at) WHERE state IN ('running', 'warning')");
        DB::statement("CREATE INDEX ticket_sla_timers_warning_pidx ON ticket_sla_timers (warning_at) WHERE state = 'running'");
        DB::statement('CREATE INDEX ticket_sla_timers_tenant_met_idx ON ticket_sla_timers (tenant_id, kind, met_at) WHERE met_at IS NOT NULL');

        Schema::create('sla_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('timer_id');
            $table->uuid('ticket_id');
            $table->string('type', 24);
            $table->jsonb('payload');
            $table->timestampTz('created_at');
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'timer_id'])->references(['tenant_id', 'id'])->on('ticket_sla_timers')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'ticket_id'])->references(['tenant_id', 'id'])->on('tickets')->cascadeOnDelete();
            $table->index(['tenant_id', 'ticket_id', 'created_at']);
            $table->index(['tenant_id', 'timer_id', 'type']);
        });
        DB::statement("ALTER TABLE sla_events ADD CONSTRAINT sla_events_type_check CHECK (type IN ('started', 'paused', 'resumed', 'warning', 'breached', 'met', 'met_late', 'recomputed', 'cancelled'))");

        foreach (['business_calendars', 'calendar_holidays', 'sla_policies', 'sla_targets', 'ticket_sla_timers', 'sla_events'] as $table) {
            TenantTables::protectTenantId($table);
            ReportableTables::captureChanges($table);
        }

        // SLA events are append-only for the runtime role (docs/08-database/overview.md).
        $runtimeRole = (string) config('database.connections.pgsql.username');
        $roleExists = DB::selectOne('SELECT 1 AS present FROM pg_roles WHERE rolname = ? AND rolname <> current_user', [$runtimeRole]);

        if ($roleExists !== null) {
            DB::statement(sprintf('REVOKE UPDATE, DELETE, TRUNCATE ON sla_events FROM %s', DB::getQueryGrammar()->wrap($runtimeRole)));
        }

        // ProvisionTenant seeds workspaces created after this migration. Existing workspaces need
        // the same default before ticket creation starts using SLA policies.
        foreach (DB::table('tenants')->select('id')->cursor() as $tenant) {
            $policyId = (string) Str::uuid7();
            $now = now();

            DB::table('sla_policies')->insert([
                'id' => $policyId,
                'tenant_id' => $tenant->id,
                'name' => 'Default',
                'is_default' => true,
                'applies_to_tier' => null,
                'warning_fraction' => 0.75,
                'calendar_id' => null,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ([
                'P1' => [30, 240],
                'P2' => [60, 480],
                'P3' => [240, 1440],
                'P4' => [480, 4320],
            ] as $priority => [$response, $resolution]) {
                DB::table('sla_targets')->insert([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenant->id,
                    'policy_id' => $policyId,
                    'priority_level' => $priority,
                    'first_response_minutes' => $response,
                    'resolution_minutes' => $resolution,
                ]);
            }
        }
    }

    public function down(): void
    {
        foreach (['sla_events', 'ticket_sla_timers', 'sla_targets', 'sla_policies', 'calendar_holidays', 'business_calendars'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
