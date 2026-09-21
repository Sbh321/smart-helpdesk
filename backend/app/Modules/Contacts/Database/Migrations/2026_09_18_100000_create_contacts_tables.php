<?php

declare(strict_types=1);

use App\Modules\Reporting\Support\ReportableTables;
use App\Modules\Tenancy\Support\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Organisations, contacts and tags (docs/04-domain/contacts.md, docs/08-database/entities.md,
 * docs/08-database/indexing.md §Contacts and organisations).
 *
 * Composite foreign keys on (tenant_id, id) make the database itself refuse a contact that points
 * at another workspace's organisation (docs/08-database/tenancy.md §Cross-tenant foreign keys).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('domain', 253)->nullable();
            $table->string('tier', 16)->default('standard');
            $table->jsonb('external_ids')->default('{}');
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id']);
        });
        DB::statement('CREATE UNIQUE INDEX organizations_tenant_name_unique ON organizations (tenant_id, lower(name))');
        DB::statement("ALTER TABLE organizations ADD CONSTRAINT organizations_tier_check CHECK (tier IN ('standard', 'premium', 'enterprise'))");
        DB::statement('CREATE INDEX organizations_name_trgm_gin ON organizations USING gin (name gin_trgm_ops)');

        Schema::create('contacts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('organization_id')->nullable();
            $table->string('name', 120);
            $table->string('email', 254);
            $table->string('phone', 32)->nullable();
            $table->jsonb('external_ids')->default('{}');
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('last_ticket_at')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'name'], 'contacts_tenant_name_idx');
            $table->index(['tenant_id', 'organization_id'], 'contacts_tenant_org_idx');
        });
        TenantTables::nullableForeign('contacts', 'organization_id', 'organizations', 'contacts_organization_fk');
        DB::statement('CREATE UNIQUE INDEX contacts_tenant_email_key ON contacts (tenant_id, lower(email))');
        DB::statement('CREATE INDEX contacts_name_trgm_gin ON contacts USING gin (name gin_trgm_ops)');
        DB::statement('CREATE INDEX contacts_email_trgm_gin ON contacts USING gin (email gin_trgm_ops)');
        DB::statement('CREATE INDEX contacts_active_pidx ON contacts (tenant_id, name) WHERE archived_at IS NULL');

        Schema::create('tags', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 40);
            $table->string('slug', 40);
            $table->string('color', 16)->nullable()->comment('token name, e.g. accent-3');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'slug']);
        });
        DB::statement('CREATE UNIQUE INDEX tags_tenant_name_unique ON tags (tenant_id, lower(name))');
        DB::statement('CREATE INDEX tags_name_trgm_gin ON tags USING gin (name gin_trgm_ops)');

        Schema::create('taggables', function (Blueprint $table): void {
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('tag_id');
            $table->string('taggable_type', 64);
            $table->uuid('taggable_id');

            $table->primary(['tenant_id', 'tag_id', 'taggable_type', 'taggable_id']);
            $table->index(['tenant_id', 'taggable_type', 'taggable_id'], 'taggables_subject_idx');
            $table->foreign(['tenant_id', 'tag_id'], 'taggables_tag_fk')
                ->references(['tenant_id', 'id'])->on('tags')->cascadeOnDelete();
        });

        foreach (['organizations', 'contacts', 'tags', 'taggables'] as $table) {
            TenantTables::protectTenantId($table);
        }

        ReportableTables::captureChanges('organizations');
        ReportableTables::captureChanges('contacts');
    }

    public function down(): void
    {
        Schema::dropIfExists('taggables');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('organizations');
    }
};
