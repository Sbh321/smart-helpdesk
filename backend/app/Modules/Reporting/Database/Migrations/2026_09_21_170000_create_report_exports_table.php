<?php

declare(strict_types=1);

use App\Modules\Tenancy\Support\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * Report and ticket-list exports (docs/08-database/entities.md §report_exports, ADR-0022 §6). The file
 * itself is a Media item in the system `Reports` folder, which this migration adds to the allowed
 * system folders and creates for every existing workspace. Not reportable: an export is a delivery,
 * not business data (like `notifications`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // A catalogue id (`rpt-t01`) or `tickets-list`.
            $table->string('report_key', 40);
            // The request as it will be re-run: report parameters with a fixed period, or ticket-list filters.
            $table->jsonb('parameters')->default('{}');
            $table->string('format', 4);
            $table->string('state', 10)->default('queued');
            $table->uuid('requested_by_user_id');
            $table->uuid('media_item_id')->nullable();
            $table->integer('row_count')->nullable();
            // Why a failed export failed: too_large, quota_exceeded, forbidden, failed.
            $table->string('error', 40)->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'requested_by_user_id', 'created_at'], 'report_exports_requester_idx');
        });

        DB::statement("ALTER TABLE report_exports ADD CONSTRAINT report_exports_format_check CHECK (format IN ('csv', 'xlsx'))");
        DB::statement("ALTER TABLE report_exports ADD CONSTRAINT report_exports_state_check CHECK (state IN ('queued', 'running', 'ready', 'failed'))");
        DB::statement('ALTER TABLE report_exports ADD CONSTRAINT report_exports_requester_fk FOREIGN KEY (tenant_id, requested_by_user_id) REFERENCES users (tenant_id, id) ON DELETE CASCADE');
        TenantTables::nullableForeign('report_exports', 'media_item_id', 'media_items', 'report_exports_media_fk');
        TenantTables::protectTenantId('report_exports');

        DB::statement('ALTER TABLE media_folders DROP CONSTRAINT media_folders_system_check');
        DB::statement("ALTER TABLE media_folders ADD CONSTRAINT media_folders_system_check CHECK (system_key IS NULL OR system_key IN ('tickets', 'email', 'branding', 'reports'))");

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            DB::table('media_folders')->insertOrIgnore([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'parent_id' => null,
                'name' => 'Reports',
                'system_key' => 'reports',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
        DB::table('media_folders')->where('system_key', 'reports')->whereNotExists(
            fn ($items) => $items->selectRaw('1')->from('media_items')->whereColumn('media_items.folder_id', 'media_folders.id'),
        )->delete();
        DB::statement('ALTER TABLE media_folders DROP CONSTRAINT media_folders_system_check');
        DB::statement("ALTER TABLE media_folders ADD CONSTRAINT media_folders_system_check CHECK (system_key IS NULL OR system_key IN ('tickets', 'email', 'branding', 'reports'))");
    }
};
