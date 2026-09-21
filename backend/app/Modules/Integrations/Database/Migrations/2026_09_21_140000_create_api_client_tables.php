<?php

declare(strict_types=1);

use App\Modules\Tenancy\Support\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * API clients (docs/07-api/authentication.md §3, ADR-0007): Passport's `oauth_clients` and
 * `oauth_access_tokens`, adapted for tenancy, plus the per-tenant idempotency store for
 * `POST /v1/tickets`.
 *
 * Only the client-credentials grant is enabled, so Passport's auth-code, refresh-token and
 * device-code tables are not created. Both OAuth tables are read before tenancy is initialised
 * (the token endpoint and the tenant resolver), so they are credential tables: they carry a
 * NOT NULL `tenant_id` but will never get row-level security (docs/03-architecture/tenancy.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_clients', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // Passport's owner morph stays empty: clients belong to the workspace, not a user.
            $table->nullableUuidMorphs('owner');
            $table->string('name', 120);
            $table->string('secret')->nullable();
            $table->string('provider')->nullable();
            $table->text('redirect_uris')->default('[]');
            $table->text('grant_types')->default('["client_credentials"]');
            $table->jsonb('scopes')->default('[]');
            $table->boolean('revoked')->default(false);
            $table->timestampTz('revoked_at')->nullable();
            $table->uuid('created_by_user_id')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'revoked', 'created_at']);
        });

        Schema::create('oauth_access_tokens', function (Blueprint $table): void {
            $table->char('id', 80)->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // Always null: client-credentials tokens have no user.
            $table->uuid('user_id')->nullable();
            $table->uuid('client_id');
            $table->string('name')->nullable();
            $table->text('scopes')->nullable();
            $table->boolean('revoked');
            $table->timestampsTz();
            $table->timestampTz('expires_at')->nullable();

            $table->foreign(['tenant_id', 'client_id'])->references(['tenant_id', 'id'])->on('oauth_clients')->cascadeOnDelete();
            $table->index(['tenant_id', 'client_id', 'revoked']);
        });

        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('client_id');
            $table->char('key_hash', 64);
            $table->char('request_hash', 64);
            $table->string('route', 64);
            $table->unsignedSmallInteger('response_status');
            // json, not jsonb: the replay must return the members in their original order.
            $table->json('response_body');
            $table->timestampTz('created_at');
            $table->timestampTz('expires_at');

            $table->unique(['tenant_id', 'client_id', 'key_hash']);
            $table->index(['tenant_id', 'expires_at']);
            $table->foreign(['tenant_id', 'client_id'])->references(['tenant_id', 'id'])->on('oauth_clients')->cascadeOnDelete();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        TenantTables::nullableForeign('oauth_clients', 'created_by_user_id', 'users');
        // tickets.created_by_client_id was reserved in M2-01 and now points at the client.
        TenantTables::nullableForeign('tickets', 'created_by_client_id', 'oauth_clients');

        foreach (['oauth_clients', 'oauth_access_tokens', 'idempotency_keys'] as $table) {
            TenantTables::protectTenantId($table);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE tickets DROP CONSTRAINT IF EXISTS tickets_created_by_client_id_fk');
        }

        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('oauth_access_tokens');
        Schema::dropIfExists('oauth_clients');
    }
};
