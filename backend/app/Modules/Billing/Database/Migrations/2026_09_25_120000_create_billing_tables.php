<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Plans, subscriptions and receipt payments (ADR-0025, roadmap M6-01, M6-02). All three are central:
 * `plans` is control-plane data; `subscriptions` and `subscription_payments` carry `tenant_id` without
 * row-level security, like `domains`, and the workspace endpoints filter by it explicitly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name', 80);
            $table->string('description', 500)->nullable();
            $table->string('kind', 10);
            $table->bigInteger('price_minor')->default(0);
            $table->char('currency', 3)->default('NPR');
            $table->smallInteger('period_months')->nullable();
            $table->smallInteger('trial_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->smallInteger('sort_order')->default(0);
            $table->timestampsTz();
        });
        DB::statement(<<<'SQL'
            ALTER TABLE plans
              ADD CONSTRAINT plans_kind_check CHECK (kind IN ('trial', 'paid')),
              ADD CONSTRAINT plans_price_check CHECK (price_minor >= 0),
              ADD CONSTRAINT plans_length_check CHECK (
                (kind = 'trial' AND trial_days BETWEEN 1 AND 365 AND period_months IS NULL AND price_minor = 0)
                OR (kind = 'paid' AND period_months BETWEEN 1 AND 36 AND trial_days IS NULL)
              )
            SQL);
        // The active trial plan is what a new workspace starts on, so there is at most one.
        DB::statement("CREATE UNIQUE INDEX plans_one_active_trial ON plans (kind) WHERE kind = 'trial' AND is_active");

        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('plan_id')->constrained('plans')->restrictOnDelete();
            $table->timestampTz('ends_at');
            // Reminder keys already sent for this `ends_at` (billing:remind); cleared when it moves.
            $table->jsonb('reminders')->default('[]');
            $table->timestampsTz();
            $table->index('ends_at');
        });

        Schema::create('subscription_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('plan_id')->constrained('plans')->restrictOnDelete();
            $table->smallInteger('periods');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->date('paid_on');
            $table->string('method', 20);
            $table->string('reference', 120)->nullable();
            $table->string('note', 1000)->nullable();
            // A media item of the same workspace (application plane), linked through `mediables`.
            $table->uuid('receipt_media_id')->nullable();
            $table->string('status', 10)->default('pending');
            $table->string('rejection_reason', 500)->nullable();
            $table->uuid('submitted_by_user_id')->nullable();
            $table->string('submitted_by_name', 120)->nullable();
            $table->string('submitted_by_email', 254)->nullable();
            $table->uuid('recorded_by_platform_user_id')->nullable();
            $table->uuid('reviewed_by_platform_user_id')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampTz('period_starts_at')->nullable();
            $table->timestampTz('period_ends_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'created_at']);
            $table->index(['tenant_id', 'created_at']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE subscription_payments
              ADD CONSTRAINT subscription_payments_status_check CHECK (status IN ('pending', 'approved', 'rejected')),
              ADD CONSTRAINT subscription_payments_method_check CHECK (method IN ('bank_transfer', 'wallet', 'cash', 'other')),
              ADD CONSTRAINT subscription_payments_periods_check CHECK (periods BETWEEN 1 AND 36),
              ADD CONSTRAINT subscription_payments_amount_check CHECK (amount_minor > 0),
              ADD CONSTRAINT subscription_payments_rejection_check CHECK (status <> 'rejected' OR rejection_reason IS NOT NULL)
            SQL);

        // Receipts live in each workspace's system Billing folder (ADR-0025 §4).
        DB::statement('ALTER TABLE media_folders DROP CONSTRAINT media_folders_system_check');
        DB::statement("ALTER TABLE media_folders ADD CONSTRAINT media_folders_system_check CHECK (system_key IS NULL OR system_key IN ('tickets', 'email', 'branding', 'reports', 'billing'))");

        // …and are linked to their payment, which keeps them out of the trash while in use.
        DB::statement('ALTER TABLE mediables DROP CONSTRAINT mediables_type_check');
        DB::statement("ALTER TABLE mediables ADD CONSTRAINT mediables_type_check CHECK (mediable_type IN ('ticket', 'ticket_comment', 'tenant_branding', 'inbound_email', 'subscription_payment'))");
        DB::statement('ALTER TABLE mediables DROP CONSTRAINT mediables_role_check');
        DB::statement("ALTER TABLE mediables ADD CONSTRAINT mediables_role_check CHECK (role IN ('attachment', 'logo', 'inline', 'receipt'))");

        $now = now();
        $trial = (string) Str::uuid7();
        $standard = (string) Str::uuid7();
        DB::table('plans')->insert([
            [
                'id' => $trial, 'code' => 'trial', 'name' => 'Free trial',
                'description' => 'Every feature for 14 days, no payment needed.',
                'kind' => 'trial', 'price_minor' => 0, 'currency' => 'NPR', 'period_months' => null,
                'trial_days' => 14, 'is_active' => true, 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'id' => $standard, 'code' => 'standard', 'name' => 'Standard',
                'description' => 'Every feature, paid by the month.',
                'kind' => 'paid', 'price_minor' => 250000, 'currency' => 'NPR', 'period_months' => 1,
                'trial_days' => null, 'is_active' => true, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now,
            ],
        ]);

        // Workspaces that ran before billing keep running: Standard for a year from today.
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            DB::table('subscriptions')->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'plan_id' => $standard,
                'ends_at' => $now->copy()->addYear(), 'reminders' => '[]', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        DB::table('platform_settings')->insertOrIgnore([
            ['key' => 'billing', 'value' => json_encode(['grace_days' => 7]), 'updated_at' => $now],
            ['key' => 'signup', 'value' => json_encode(['enabled' => true]), 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        DB::table('platform_settings')->whereIn('key', ['billing', 'signup'])->delete();
        DB::statement('ALTER TABLE media_folders DROP CONSTRAINT media_folders_system_check');
        DB::statement("ALTER TABLE media_folders ADD CONSTRAINT media_folders_system_check CHECK (system_key IS NULL OR system_key IN ('tickets', 'email', 'branding', 'reports'))");
        DB::table('mediables')->where('mediable_type', 'subscription_payment')->delete();
        DB::statement('ALTER TABLE mediables DROP CONSTRAINT mediables_type_check');
        DB::statement("ALTER TABLE mediables ADD CONSTRAINT mediables_type_check CHECK (mediable_type IN ('ticket', 'ticket_comment', 'tenant_branding', 'inbound_email'))");
        DB::statement('ALTER TABLE mediables DROP CONSTRAINT mediables_role_check');
        DB::statement("ALTER TABLE mediables ADD CONSTRAINT mediables_role_check CHECK (role IN ('attachment', 'logo', 'inline'))");
        Schema::dropIfExists('subscription_payments');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};
