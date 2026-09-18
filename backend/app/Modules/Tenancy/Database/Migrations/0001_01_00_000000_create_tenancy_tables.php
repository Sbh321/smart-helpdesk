<?php

declare(strict_types=1);

use App\Modules\Tenancy\Support\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Control plane: tenants, domains, counters; application plane: tenant_settings
 * (docs/08-database/entities.md §Platform / Tenancy). Runs before every other table.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_tenant_id_change() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.tenant_id IS DISTINCT FROM OLD.tenant_id THEN
                    RAISE EXCEPTION 'tenant_id is immutable on %', TG_TABLE_NAME USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$;
            SQL);

        Schema::create('tenants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug', 63)->unique();
            $table->string('name', 120);
            $table->string('status', 16)->default('active');
            $table->string('plan', 32)->default('standard');
            $table->string('placement', 16)->default('shared');
            $table->string('owner_email', 254)->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->timestampTz('suspended_at')->nullable();
            $table->timestampTz('archived_at')->nullable();
            // stancl/tenancy keeps attributes that are not real columns here; unused by our code.
            $table->jsonb('data')->nullable();
            $table->timestampsTz();
        });

        $reserved = collect(config('helpdesk.reserved_slugs'))
            ->map(fn (string $slug): string => DB::getPdo()->quote($slug))
            ->implode(', ');

        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_slug_format_check CHECK (slug ~ '^[a-z0-9](-?[a-z0-9])*$')");
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_slug_reserved_check CHECK (slug NOT IN ({$reserved}))");
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_status_check CHECK (status IN ('active', 'suspended', 'archived'))");
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_placement_check CHECK (placement IN ('shared', 'dedicated'))");

        Schema::create('domains', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('domain', 253)->unique();
            $table->boolean('is_primary')->default(false);
            $table->timestampTz('verified_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX domains_one_primary_per_tenant ON domains (tenant_id) WHERE is_primary');

        Schema::create('tenant_counters', function (Blueprint $table): void {
            $table->foreignUuid('tenant_id')->primary()->constrained('tenants')->cascadeOnDelete();
            $table->integer('next_ticket_number')->default(1);
        });

        Schema::create('tenant_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->jsonb('data')->default('{}');
            $table->timestampsTz();
        });
        TenantTables::protectTenantId('tenant_settings');
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_settings');
        Schema::dropIfExists('tenant_counters');
        Schema::dropIfExists('domains');
        Schema::dropIfExists('tenants');
        DB::unprepared('DROP FUNCTION IF EXISTS prevent_tenant_id_change()');
    }
};
