<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Control plane (docs/08-database/entities.md §Platform). Platform users are not tenant users:
 * a separate table, a separate guard and a separate cookie on the admin host (ADR-0021).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('email', 254);
            $table->string('password');
            $table->rememberToken();
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampsTz();
        });
        // Case-insensitive uniqueness, like tenant users.
        DB::statement('CREATE UNIQUE INDEX platform_users_email_unique ON platform_users (lower(email))');

        Schema::create('platform_settings', function (Blueprint $table): void {
            $table->string('key', 64)->primary();
            $table->jsonb('value')->default('{}');
            $table->timestampTz('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('platform_users');
    }
};
