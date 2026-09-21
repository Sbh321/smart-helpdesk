<?php

declare(strict_types=1);

use App\Modules\Tenancy\Support\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * In-app notifications (docs/04-domain/notifications.md): Laravel's database channel table plus
 * `tenant_id` and the dedupe key. Not reportable: a notification is a delivery, not business data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('type', 60);
            $table->string('notifiable_type', 40);
            $table->uuid('notifiable_id');
            $table->jsonb('data');
            // `sla_warning:{timer_id}`: one notification per recipient and occurrence, also when a job is retried.
            $table->string('notification_key', 120);
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'notifiable_id', 'notification_key'], 'notifications_dedupe_uidx');
            $table->index(['tenant_id', 'notifiable_id', 'read_at', 'created_at'], 'notifications_inbox_idx');
        });

        DB::statement('ALTER TABLE notifications ADD CONSTRAINT notifications_user_fk FOREIGN KEY (tenant_id, notifiable_id) REFERENCES users (tenant_id, id) ON DELETE CASCADE');
        TenantTables::protectTenantId('notifications');
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
