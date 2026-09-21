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
        Schema::table('tenants', function (Blueprint $table): void {
            // 5 GiB, the value of helpdesk.media.default_quota_bytes (docs/04-domain/media.md).
            $table->unsignedBigInteger('storage_quota_bytes')->default(5368709120);
        });
        Schema::table('tenant_counters', function (Blueprint $table): void {
            $table->unsignedBigInteger('storage_used_bytes')->default(0);
        });

        Schema::create('media_folders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('parent_id')->nullable();
            $table->string('name', 120);
            $table->string('system_key', 20)->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'parent_id'], 'media_folders_parent_fk')
                ->references(['tenant_id', 'id'])->on('media_folders')->restrictOnDelete();
        });
        DB::statement('CREATE UNIQUE INDEX media_folders_sibling_name_key ON media_folders (tenant_id, parent_id, lower(name)) NULLS NOT DISTINCT');
        DB::statement('CREATE UNIQUE INDEX media_folders_system_key ON media_folders (tenant_id, system_key) WHERE system_key IS NOT NULL');
        DB::statement("ALTER TABLE media_folders ADD CONSTRAINT media_folders_system_check CHECK (system_key IS NULL OR system_key IN ('tickets', 'email', 'branding'))");

        Schema::create('media_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('folder_id')->nullable();
            $table->string('name', 255);
            $table->string('storage_key', 512);
            $table->string('mime_type', 127);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->char('checksum_sha256', 64)->nullable();
            $table->jsonb('variants')->default('{}');
            $table->string('source', 8)->default('upload');
            $table->string('state', 8)->default('pending');
            $table->uuid('uploaded_by_user_id')->nullable();
            $table->timestampTz('trashed_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'storage_key']);
            $table->index(['tenant_id', 'folder_id', 'created_at', 'id'], 'media_items_folder_created_idx');
        });
        TenantTables::nullableForeign('media_items', 'folder_id', 'media_folders', 'media_items_folder_fk');
        TenantTables::nullableForeign('media_items', 'uploaded_by_user_id', 'users', 'media_items_uploader_fk');
        DB::statement('CREATE INDEX media_items_name_trgm_gin ON media_items USING gin (name gin_trgm_ops)');
        DB::statement("CREATE INDEX media_items_pending_idx ON media_items (tenant_id, created_at) WHERE state = 'pending'");
        DB::statement("CREATE INDEX media_items_trashed_idx ON media_items (tenant_id, trashed_at) WHERE state = 'trashed'");
        DB::statement('ALTER TABLE media_items ADD CONSTRAINT media_items_size_check CHECK (size_bytes > 0 AND size_bytes <= 26214400)');
        DB::statement("ALTER TABLE media_items ADD CONSTRAINT media_items_source_check CHECK (source IN ('upload', 'email', 'api', 'system'))");
        DB::statement("ALTER TABLE media_items ADD CONSTRAINT media_items_state_check CHECK (state IN ('pending', 'ready', 'trashed', 'failed'))");

        Schema::create('mediables', function (Blueprint $table): void {
            $table->uuid('id')->default(DB::raw('uuidv7()'))->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('media_item_id');
            $table->string('mediable_type', 40);
            $table->uuid('mediable_id');
            $table->string('role', 12);
            $table->timestampTz('created_at');

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'media_item_id', 'mediable_type', 'mediable_id', 'role'], 'mediables_link_key');
            $table->foreign(['tenant_id', 'media_item_id'], 'mediables_media_fk')
                ->references(['tenant_id', 'id'])->on('media_items')->restrictOnDelete();
            $table->index(['tenant_id', 'mediable_type', 'mediable_id'], 'mediables_subject_idx');
        });
        DB::statement("ALTER TABLE mediables ADD CONSTRAINT mediables_type_check CHECK (mediable_type IN ('ticket', 'ticket_comment', 'tenant_branding', 'inbound_email'))");
        DB::statement("ALTER TABLE mediables ADD CONSTRAINT mediables_role_check CHECK (role IN ('attachment', 'logo', 'inline'))");

        foreach (['media_folders', 'media_items', 'mediables'] as $table) {
            TenantTables::protectTenantId($table);
        }

        foreach (['media_folders', 'media_items', 'mediables'] as $table) {
            ReportableTables::captureChanges($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mediables');
        Schema::dropIfExists('media_items');
        Schema::dropIfExists('media_folders');
        Schema::table('tenant_counters', fn (Blueprint $table) => $table->dropColumn('storage_used_bytes'));
        Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn('storage_quota_bytes'));
    }
};
