<?php

declare(strict_types=1);

use App\Modules\Tenancy\Support\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Outbound webhooks (docs/07-api/webhooks.md, docs/08-database/entities.md §Integrations).
 *
 * Both tables are primary tenant tables: `tenant_id NOT NULL`, immutable, and every delivery points
 * at its subscription through the composite (tenant_id, subscription_id) key, so a delivery can
 * never hang off another workspace's subscription.
 *
 * Neither table is reportable in the ADR-0022 sense (no change-capture trigger): subscriptions are
 * configuration whose changes are audited, and deliveries are an append-mostly log that RPT-I01
 * (webhook reliability) reads directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('url', 2048);
            // Encrypted cast: the column holds Laravel's ciphertext, never the secret itself.
            $table->text('secret');
            $table->text('previous_secret')->nullable();
            $table->timestampTz('previous_secret_expires_at')->nullable();
            $table->string('api_version', 4)->default('v1');
            $table->boolean('is_active')->default(true);
            $table->integer('consecutive_failures')->default(0);
            $table->timestampTz('disabled_at')->nullable();
            $table->string('disabled_reason', 32)->nullable();
            $table->uuid('created_by_user_id')->nullable();
            $table->timestampTz('last_delivery_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('subscription_id');
            $table->uuid('event_id');
            $table->string('event_type', 40);
            // json, not jsonb: the envelope is sent with its members in their documented order.
            $table->json('payload');
            $table->string('state', 10)->default('pending');
            // Attempts over the delivery's life, and within the current retry sequence (a manual
            // retry starts a new sequence; the backoff index follows the sequence).
            $table->smallInteger('attempt')->default(0);
            $table->smallInteger('sequence_attempt')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('last_attempted_at')->nullable();
            $table->smallInteger('response_status')->nullable();
            $table->string('response_excerpt', 1024)->nullable();
            $table->string('error', 255)->nullable();
            $table->integer('duration_ms')->nullable();
            $table->smallInteger('manual_retries')->default(0);
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'subscription_id'])->references(['tenant_id', 'id'])->on('webhook_subscriptions')->cascadeOnDelete();
            $table->index(['subscription_id', 'created_at', 'id'], 'webhook_deliveries_sub_created_idx');
            $table->index(['created_at'], 'webhook_deliveries_prune_idx');
            $table->index(['tenant_id', 'event_id'], 'webhook_deliveries_event_idx');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // An explicit list of catalogue events, never empty (webhooks.md §Subscription model).
        DB::statement("ALTER TABLE webhook_subscriptions ADD COLUMN events text[] NOT NULL DEFAULT '{}'");
        DB::statement('ALTER TABLE webhook_subscriptions ALTER COLUMN events DROP DEFAULT');
        DB::statement('ALTER TABLE webhook_subscriptions ADD CONSTRAINT webhook_subscriptions_events_check CHECK (cardinality(events) > 0)');
        DB::statement('CREATE INDEX webhook_subscriptions_tenant_active_idx ON webhook_subscriptions (tenant_id) WHERE is_active');

        DB::statement("ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_state_check CHECK (state IN ('pending', 'succeeded', 'failed', 'dead'))");
        // The minute sweep (`webhooks:retry-due`) reads due retries and stale queued attempts.
        DB::statement("CREATE INDEX webhook_deliveries_retry_pidx ON webhook_deliveries (next_attempt_at) WHERE state IN ('pending', 'failed')");

        TenantTables::nullableForeign('webhook_subscriptions', 'created_by_user_id', 'users');

        foreach (['webhook_subscriptions', 'webhook_deliveries'] as $table) {
            TenantTables::protectTenantId($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_subscriptions');
    }
};
