<?php

declare(strict_types=1);

use App\Modules\Tenancy\Support\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Invitations to join a workspace (docs/08-database/entities.md §invitations,
 * docs/07-api/authentication.md §2). The user row exists before the invitation is accepted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // Unique per tenant, not globally: the accept route initialises the workspace first,
            // so the lookup is already tenant-scoped, and every unique index leads with tenant_id.
            $table->string('token_hash', 64)->comment('sha256 of the token in the link');
            $table->jsonb('role_names')->default('[]');
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'token_hash']);
            $table->index(['tenant_id', 'user_id']);
        });
        TenantTables::protectTenantId('invitations');
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
