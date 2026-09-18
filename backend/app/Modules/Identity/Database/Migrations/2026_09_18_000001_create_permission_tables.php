<?php

declare(strict_types=1);

use App\Modules\Tenancy\Support\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * spatie/laravel-permission in teams mode with `tenant_id` as the team key
 * (ADR-0007, docs/08-database/entities.md §roles). UUID keys like every other table.
 *
 * `roles.tenant_id` is nullable: null means a global default role shared by every workspace.
 * Assignments always carry the tenant, so two workspaces never see each other's grants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 64);
            $table->string('guard_name', 16);
            $table->timestampsTz();

            $table->unique(['name', 'guard_name']);
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // null = global default role, shared by every workspace
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 64);
            $table->string('guard_name', 16);
            $table->boolean('is_system')->default(false);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'name', 'guard_name']);
        });
        // Global roles: one row per name, enforced where tenant_id is null.
        DB::statement('CREATE UNIQUE INDEX roles_global_name_unique ON roles (name, guard_name) WHERE tenant_id IS NULL');

        Schema::create('model_has_permissions', function (Blueprint $table): void {
            $table->foreignUuid('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->string('model_type');
            $table->uuid('model_id');
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->index(['model_id', 'model_type']);
            $table->primary(['tenant_id', 'permission_id', 'model_id', 'model_type'], 'model_has_permissions_permission_model_type_primary');
        });

        Schema::create('model_has_roles', function (Blueprint $table): void {
            $table->foreignUuid('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('model_type');
            $table->uuid('model_id');
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->index(['model_id', 'model_type']);
            $table->primary(['tenant_id', 'role_id', 'model_id', 'model_type'], 'model_has_roles_role_model_type_primary');
        });

        Schema::create('role_has_permissions', function (Blueprint $table): void {
            $table->foreignUuid('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->foreignUuid('role_id')->constrained('roles')->cascadeOnDelete();

            $table->primary(['permission_id', 'role_id']);
        });

        TenantTables::protectTenantId('model_has_roles');
        TenantTables::protectTenantId('model_has_permissions');
    }

    public function down(): void
    {
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('model_has_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
