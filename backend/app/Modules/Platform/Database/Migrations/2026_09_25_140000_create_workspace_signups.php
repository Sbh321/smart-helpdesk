<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Self sign-up (ADR-0025 §8, roadmap M6-05): a request waits here, with its password already hashed,
 * until the email link is followed; nothing else exists before that. Control plane.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_signups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('email', 254);
            $table->string('password');
            $table->string('workspace_name', 120);
            $table->string('slug', 63);
            $table->string('timezone', 64);
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('verified_at')->nullable();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->timestampsTz();
            $table->index('slug');
        });
        DB::statement('CREATE INDEX workspace_signups_email_index ON workspace_signups (lower(email))');
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_signups');
    }
};
