<?php

declare(strict_types=1);

use App\Modules\Tenancy\Support\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Tenant users, password resets and sessions (docs/08-database/entities.md §Identity).
 * A user belongs to exactly one tenant (ADR-0021); email is unique per tenant, case-insensitively.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('email', 254);
            $table->string('password')->nullable()->comment('null until the invitation is accepted');
            $table->boolean('is_active')->default(true);
            $table->jsonb('preferences')->default('{}');
            $table->timestampTz('email_verified_at')->nullable();
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampTz('disabled_at')->nullable();
            $table->rememberToken();
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX users_tenant_email_unique ON users (tenant_id, lower(email))');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_tenant_id_id_unique UNIQUE (tenant_id, id)');
        TenantTables::protectTenantId('users');

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('email', 254);
            $table->string('token');
            $table->timestampTz('created_at')->nullable();
            $table->primary(['tenant_id', 'email']);
        });

        // Read before tenancy is initialised, so no RLS; tenant_id is what the resolver trusts.
        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->uuid('user_id')->nullable()->index();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('guard', 16)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
