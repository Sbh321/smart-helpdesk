<?php

declare(strict_types=1);

use App\Modules\Reporting\Support\ReportableTables;
use App\Modules\Tenancy\Support\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 80);
            $table->text('description')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('agent_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('user_id');
            $table->smallInteger('capacity')->default(10);
            $table->string('availability', 16)->default('available');
            $table->integer('active_ticket_count')->default(0);
            $table->timestampTz('last_assigned_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'user_id']);
            $table->foreign(['tenant_id', 'user_id'])->references(['tenant_id', 'id'])->on('users')->cascadeOnDelete();
            $table->index(['tenant_id', 'availability', 'active_ticket_count'], 'agent_profiles_tenant_avail_idx');
        });

        DB::statement('ALTER TABLE agent_profiles ADD CONSTRAINT agent_profiles_capacity_check CHECK (capacity BETWEEN 1 AND 100)');
        DB::statement('ALTER TABLE agent_profiles ADD CONSTRAINT agent_profiles_active_count_check CHECK (active_ticket_count >= 0)');
        DB::statement("ALTER TABLE agent_profiles ADD CONSTRAINT agent_profiles_availability_check CHECK (availability IN ('available', 'away', 'offline'))");

        Schema::create('team_members', function (Blueprint $table): void {
            $table->uuid('id')->default(DB::raw('uuidv7()'))->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('team_id');
            $table->uuid('agent_profile_id');
            $table->timestampTz('joined_at');

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'team_id', 'agent_profile_id']);
            $table->foreign(['tenant_id', 'team_id'])->references(['tenant_id', 'id'])->on('teams')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'agent_profile_id'])->references(['tenant_id', 'id'])->on('agent_profiles')->cascadeOnDelete();
            $table->index(['tenant_id', 'agent_profile_id'], 'team_members_tenant_agent_idx');
        });

        Schema::create('agent_skills', function (Blueprint $table): void {
            $table->uuid('id')->default(DB::raw('uuidv7()'))->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('agent_profile_id');
            $table->uuid('skill_id');
            $table->smallInteger('level');

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'agent_profile_id', 'skill_id']);
            $table->foreign(['tenant_id', 'agent_profile_id'])->references(['tenant_id', 'id'])->on('agent_profiles')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'skill_id'])->references(['tenant_id', 'id'])->on('skills')->cascadeOnDelete();
            $table->index(['tenant_id', 'skill_id'], 'agent_skills_tenant_skill_idx');
        });
        DB::statement('ALTER TABLE agent_skills ADD CONSTRAINT agent_skills_level_check CHECK (level BETWEEN 1 AND 5)');

        Schema::create('agent_shifts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('agent_profile_id');
            $table->smallInteger('weekday')->nullable();
            $table->date('date')->nullable();
            $table->time('starts_at');
            $table->time('ends_at');
            $table->boolean('is_off')->default(false);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'agent_profile_id'])->references(['tenant_id', 'id'])->on('agent_profiles')->cascadeOnDelete();
            $table->index(['tenant_id', 'agent_profile_id', 'weekday'], 'agent_shifts_tenant_agent_weekday_idx');
            $table->index(['tenant_id', 'date'], 'agent_shifts_tenant_date_idx');
        });
        DB::statement('ALTER TABLE agent_shifts ADD CONSTRAINT agent_shifts_day_check CHECK ((weekday IS NULL) <> (date IS NULL))');
        DB::statement('ALTER TABLE agent_shifts ADD CONSTRAINT agent_shifts_weekday_check CHECK (weekday IS NULL OR weekday BETWEEN 0 AND 6)');
        DB::statement('ALTER TABLE agent_shifts ADD CONSTRAINT agent_shifts_time_check CHECK (ends_at > starts_at)');
        DB::statement('CREATE UNIQUE INDEX agent_shifts_weekly_unique ON agent_shifts (tenant_id, agent_profile_id, weekday, starts_at, ends_at) WHERE weekday IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX agent_shifts_exception_unique ON agent_shifts (tenant_id, agent_profile_id, date, starts_at, ends_at) WHERE date IS NOT NULL');

        DB::statement('ALTER TABLE categories ADD CONSTRAINT categories_default_team_fk FOREIGN KEY (tenant_id, default_team_id) REFERENCES teams (tenant_id, id) ON DELETE SET NULL (default_team_id)');
        DB::statement('ALTER TABLE tickets ADD CONSTRAINT tickets_team_fk FOREIGN KEY (tenant_id, team_id) REFERENCES teams (tenant_id, id) ON DELETE SET NULL (team_id)');
        DB::statement('ALTER TABLE tickets ADD CONSTRAINT tickets_assigned_agent_fk FOREIGN KEY (tenant_id, assigned_agent_id) REFERENCES agent_profiles (tenant_id, id) ON DELETE SET NULL (assigned_agent_id)');

        foreach (['teams', 'agent_profiles', 'team_members', 'agent_skills', 'agent_shifts'] as $table) {
            TenantTables::protectTenantId($table);
        }

        foreach (['skills', 'teams', 'agent_profiles', 'team_members', 'agent_skills', 'agent_shifts'] as $table) {
            ReportableTables::captureChanges($table);
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tickets DROP CONSTRAINT IF EXISTS tickets_assigned_agent_fk');
        DB::statement('ALTER TABLE tickets DROP CONSTRAINT IF EXISTS tickets_team_fk');
        DB::statement('ALTER TABLE categories DROP CONSTRAINT IF EXISTS categories_default_team_fk');
        DB::statement('DROP TRIGGER IF EXISTS skills_changes ON skills');

        foreach (['agent_shifts', 'agent_skills', 'team_members', 'agent_profiles', 'teams'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
