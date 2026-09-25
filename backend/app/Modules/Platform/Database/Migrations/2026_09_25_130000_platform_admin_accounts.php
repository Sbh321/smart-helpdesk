<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform admin accounts (ADR-0025 §7, roadmap M6-04): an invited admin has no password until they
 * accept; admins are deactivated, never deleted; invitations and password resets have their own tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_users', function (Blueprint $table): void {
            $table->string('password')->nullable()->change();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('deactivated_at')->nullable();
            $table->uuid('invited_by_id')->nullable();
        });

        Schema::create('platform_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('platform_user_id')->constrained('platform_users')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->uuid('invited_by_id')->nullable();
            $table->timestampsTz();
        });

        Schema::create('platform_password_reset_tokens', function (Blueprint $table): void {
            $table->string('email', 254)->primary();
            $table->string('token');
            $table->timestampTz('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_password_reset_tokens');
        Schema::dropIfExists('platform_invitations');
        Schema::table('platform_users', function (Blueprint $table): void {
            $table->dropColumn(['is_active', 'deactivated_at', 'invited_by_id']);
        });
    }
};
