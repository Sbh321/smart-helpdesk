<?php

declare(strict_types=1);

use App\Modules\Reporting\Support\ReportableTables;
use App\Modules\Tenancy\Support\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Tickets and their children (docs/04-domain/tickets.md, docs/08-database/entities.md,
 * docs/08-database/indexing.md §Tickets).
 *
 * team_id and assigned_agent_id have no foreign key yet: teams and agent_profiles arrive with the
 * Agents module (M2-02), whose migration adds the composite keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skills', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('slug', 60);
            $table->text('description')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'name']);
            $table->unique(['tenant_id', 'slug']);
        });

        Schema::create('categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 80);
            // MVP-SHORTCUT: no foreign key until teams exist; V1: none (M2-02 adds the composite key).
            $table->uuid('default_team_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->smallInteger('sort_order')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('category_skill', function (Blueprint $table): void {
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('category_id');
            $table->uuid('skill_id');

            $table->primary(['tenant_id', 'category_id', 'skill_id']);
            $table->foreign(['tenant_id', 'category_id'])->references(['tenant_id', 'id'])->on('categories')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'skill_id'])->references(['tenant_id', 'id'])->on('skills')->cascadeOnDelete();
        });

        Schema::create('tickets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->integer('number');
            $table->string('title', 200);
            $table->text('description');
            $table->uuid('contact_id');
            $table->uuid('organization_id')->nullable();
            $table->uuid('category_id');
            $table->uuid('team_id')->nullable();
            $table->uuid('assigned_agent_id')->nullable();
            $table->string('status', 16)->default('open');
            $table->smallInteger('impact');
            $table->smallInteger('urgency');
            $table->decimal('priority_score', 5, 2)->default(0);
            $table->string('priority_level', 2)->default('P4');
            $table->string('priority_override_level', 2)->nullable();
            $table->string('priority_override_reason', 255)->nullable();
            $table->foreignUuid('priority_override_by')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('priority_explanation')->default('{}');
            $table->integer('priority_settings_version')->nullable();
            $table->uuid('duplicate_of_id')->nullable();
            $table->smallInteger('reopen_count')->default(0);
            $table->integer('version')->default(1);
            $table->timestampTz('first_responded_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampTz('last_customer_reply_at')->nullable();
            $table->timestampTz('last_agent_reply_at')->nullable();
            $table->timestampTz('pending_since')->nullable();
            $table->integer('paused_total_seconds')->default(0);
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // MVP-SHORTCUT: API clients are Sanctum tokens until M3-04; V1: none (M3-04 adds the key to oauth_clients).
            $table->uuid('created_by_client_id')->nullable();
            $table->string('created_via', 8)->default('ui');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'number']);
            $table->foreign(['tenant_id', 'contact_id'], 'tickets_contact_fk')->references(['tenant_id', 'id'])->on('contacts');
            $table->foreign(['tenant_id', 'organization_id'], 'tickets_organization_fk')->references(['tenant_id', 'id'])->on('organizations');
            $table->foreign(['tenant_id', 'category_id'], 'tickets_category_fk')->references(['tenant_id', 'id'])->on('categories');
            $table->foreign(['tenant_id', 'duplicate_of_id'], 'tickets_duplicate_of_fk')->references(['tenant_id', 'id'])->on('tickets');

            $table->index(['tenant_id', 'updated_at'], 'tickets_tenant_updated_idx');
            $table->index(['tenant_id', 'assigned_agent_id', 'status'], 'tickets_tenant_agent_status_idx');
            $table->index(['tenant_id', 'team_id', 'status'], 'tickets_tenant_team_status_idx');
            $table->index(['tenant_id', 'organization_id'], 'tickets_tenant_org_idx');
            $table->index(['tenant_id', 'category_id'], 'tickets_tenant_category_idx');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE tickets ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (
                setweight(to_tsvector('english', coalesce(title, '')), 'A') ||
                setweight(to_tsvector('english', coalesce(description, '')), 'B')
            ) STORED
            SQL);
        DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_status_check CHECK (status IN ('open', 'assigned', 'in_progress', 'pending', 'resolved', 'closed'))");
        DB::statement('ALTER TABLE tickets ADD CONSTRAINT tickets_impact_check CHECK (impact BETWEEN 1 AND 4)');
        DB::statement('ALTER TABLE tickets ADD CONSTRAINT tickets_urgency_check CHECK (urgency BETWEEN 1 AND 4)');
        DB::statement('ALTER TABLE tickets ADD CONSTRAINT tickets_priority_score_check CHECK (priority_score BETWEEN 0 AND 100)');
        DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_priority_level_check CHECK (priority_level IN ('P1', 'P2', 'P3', 'P4'))");
        DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_priority_override_check CHECK (priority_override_level IS NULL OR priority_override_level IN ('P1', 'P2', 'P3', 'P4'))");
        DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_created_via_check CHECK (created_via IN ('ui', 'api', 'seed'))");
        // Invariant 3 (docs/04-domain/tickets.md): resolved_at is set exactly when the ticket is resolved or closed.
        DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_resolved_at_check CHECK ((resolved_at IS NOT NULL) = (status IN ('resolved', 'closed')))");
        DB::statement('ALTER TABLE tickets ADD CONSTRAINT tickets_not_own_duplicate_check CHECK (duplicate_of_id IS NULL OR duplicate_of_id <> id)');

        DB::statement('CREATE INDEX tickets_tenant_created_idx ON tickets (tenant_id, created_at DESC, id)');
        DB::statement('CREATE INDEX tickets_tenant_contact_idx ON tickets (tenant_id, contact_id, created_at DESC)');
        DB::statement("CREATE INDEX tickets_open_pidx ON tickets (tenant_id, priority_score DESC, id) WHERE status NOT IN ('resolved', 'closed')");
        DB::statement("CREATE INDEX tickets_unassigned_pidx ON tickets (tenant_id, created_at) WHERE assigned_agent_id IS NULL AND status = 'open'");
        DB::statement('CREATE INDEX tickets_default_sort_idx ON tickets (tenant_id, priority_score DESC, created_at DESC, id)');
        DB::statement('CREATE INDEX tickets_search_gin ON tickets USING gin (search_vector)');
        DB::statement('CREATE INDEX tickets_title_trgm_gin ON tickets USING gin (title gin_trgm_ops)');
        DB::statement('CREATE INDEX tickets_duplicate_of_idx ON tickets (duplicate_of_id) WHERE duplicate_of_id IS NOT NULL');
        DB::statement('CREATE INDEX tickets_resolved_at_idx ON tickets (tenant_id, resolved_at) WHERE resolved_at IS NOT NULL');

        Schema::create('ticket_comments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('ticket_id');
            $table->string('visibility', 8);
            $table->string('author_type', 8);
            $table->uuid('author_id')->nullable();
            $table->text('body');
            $table->timestampTz('edited_at')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'ticket_id'])->references(['tenant_id', 'id'])->on('tickets')->cascadeOnDelete();
            $table->index(['tenant_id', 'ticket_id', 'created_at'], 'ticket_comments_ticket_idx');
        });
        DB::statement("ALTER TABLE ticket_comments ADD CONSTRAINT ticket_comments_visibility_check CHECK (visibility IN ('public', 'internal'))");
        DB::statement("ALTER TABLE ticket_comments ADD CONSTRAINT ticket_comments_author_type_check CHECK (author_type IN ('user', 'contact', 'client'))");

        Schema::create('ticket_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('ticket_id');
            $table->string('type', 32);
            $table->string('actor_type', 8);
            $table->uuid('actor_id')->nullable();
            $table->jsonb('old_values')->default('{}');
            $table->jsonb('new_values')->default('{}');
            $table->string('note', 255)->nullable();
            $table->timestampTz('created_at', 6);

            $table->foreign(['tenant_id', 'ticket_id'])->references(['tenant_id', 'id'])->on('tickets')->cascadeOnDelete();
        });
        DB::statement('CREATE INDEX ticket_events_ticket_idx ON ticket_events (tenant_id, ticket_id, created_at DESC, id DESC)');
        DB::statement("ALTER TABLE ticket_events ADD CONSTRAINT ticket_events_actor_type_check CHECK (actor_type IN ('user', 'client', 'system'))");
        DB::statement("ALTER TABLE ticket_events ADD CONSTRAINT ticket_events_type_check CHECK (type IN (
            'created', 'status_changed', 'priority_changed', 'priority_overridden', 'assigned', 'unassigned',
            'comment_added', 'attachment_added', 'sla_warning', 'sla_breached', 'sla_met', 'sla_recomputed',
            'escalated', 'duplicate_marked', 'duplicate_suggested', 'reopened', 'tags_changed', 'edited'))");

        Schema::create('ticket_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('ticket_id');
            $table->uuid('team_id')->nullable();
            $table->uuid('agent_profile_id')->nullable();
            $table->uuid('previous_agent_profile_id')->nullable();
            $table->string('reason', 16);
            $table->foreignUuid('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('explanation')->default('{}');
            $table->integer('settings_version')->nullable();
            $table->timestampTz('created_at', 6);

            $table->foreign(['tenant_id', 'ticket_id'])->references(['tenant_id', 'id'])->on('tickets')->cascadeOnDelete();
            $table->index(['tenant_id', 'ticket_id', 'created_at'], 'ticket_assignments_ticket_idx');
        });
        DB::statement("ALTER TABLE ticket_assignments ADD CONSTRAINT ticket_assignments_reason_check CHECK (reason IN ('auto', 'manual', 'reassign', 'unassign'))");

        foreach (['skills', 'categories', 'category_skill', 'tickets', 'ticket_comments', 'ticket_events', 'ticket_assignments'] as $table) {
            TenantTables::protectTenantId($table);
        }

        foreach (['categories', 'tickets', 'ticket_comments', 'ticket_assignments'] as $table) {
            ReportableTables::captureChanges($table);
        }

        // History is append-only for the runtime role (docs/08-database/overview.md).
        $runtimeRole = (string) config('database.connections.pgsql.username');
        $roleExists = DB::selectOne('SELECT 1 AS present FROM pg_roles WHERE rolname = ? AND rolname <> current_user', [$runtimeRole]);

        if ($roleExists !== null) {
            DB::statement(sprintf('REVOKE UPDATE, DELETE, TRUNCATE ON ticket_events FROM %s', DB::getQueryGrammar()->wrap($runtimeRole)));
        }
    }

    public function down(): void
    {
        foreach (['ticket_assignments', 'ticket_events', 'ticket_comments', 'tickets', 'category_skill', 'categories', 'skills'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
