<?php

declare(strict_types=1);

use App\Modules\Tenancy\Support\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The inbound log (docs/04-domain/email.md §Inbound pipeline, M3-19). One row per fetched message.
 *
 * `tenant_id` is nullable: a message that names no workspace (unrouted) belongs to the platform. The
 * row is written once the route is known, inside that workspace or centrally, so `tenant_id` never
 * changes after insert (the immutability trigger holds). Row-level security uses the nullable
 * variant: a workspace sees its own rows, the central context only the platform rows.
 *
 * Not reportable: a delivery log, not business data; report E01 reads the table directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_emails', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('message_id', 998);
            $table->string('from_address', 320)->nullable();
            $table->string('from_name', 200)->nullable();
            $table->jsonb('to_addresses')->default('[]');
            $table->jsonb('cc_addresses')->default('[]');
            $table->string('subject', 998)->default('');
            $table->jsonb('headers')->default('{}');
            $table->text('text_body')->nullable();
            $table->text('html_body')->nullable();
            $table->text('reply_text')->nullable();
            $table->string('state', 10);
            $table->string('route', 12)->nullable();
            $table->string('reason', 40)->nullable();
            $table->uuid('ticket_id')->nullable();
            $table->uuid('comment_id')->nullable();
            $table->uuid('contact_id')->nullable();
            $table->jsonb('attachments')->default('[]');
            $table->string('raw_key', 200)->nullable();
            $table->integer('raw_size')->default(0);
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('processed_at');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'created_at'], 'inbound_emails_feed_idx');
            $table->index(['tenant_id', 'ticket_id'], 'inbound_emails_ticket_idx');
        });

        // Idempotency: one row per Message-ID per workspace, and one per Message-ID among the platform rows.
        DB::statement('CREATE UNIQUE INDEX inbound_emails_message_key ON inbound_emails (tenant_id, message_id) NULLS NOT DISTINCT');
        DB::statement("ALTER TABLE inbound_emails ADD CONSTRAINT inbound_emails_state_check CHECK (state IN ('comment', 'ticket', 'ignored', 'unrouted', 'rejected'))");
        DB::statement("ALTER TABLE inbound_emails ADD CONSTRAINT inbound_emails_route_check CHECK (route IS NULL OR route IN ('plus_address', 'thread', 'intake'))");
        // A routed row names its workspace; the platform keeps only what no workspace can see.
        DB::statement("ALTER TABLE inbound_emails ADD CONSTRAINT inbound_emails_tenant_check CHECK (tenant_id IS NOT NULL OR state IN ('unrouted', 'rejected', 'ignored'))");

        // comment_id has no foreign key: ticket_comments has no (tenant_id, id) key to point at. A deleted
        // ticket takes its comments along and clears ticket_id here.
        TenantTables::nullableForeign('inbound_emails', 'ticket_id', 'tickets', 'inbound_emails_ticket_fk');
        TenantTables::nullableForeign('inbound_emails', 'contact_id', 'contacts', 'inbound_emails_contact_fk');

        TenantTables::protectTenantId('inbound_emails');
        TenantTables::enableRowLevelSecurity('inbound_emails');
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_emails');
    }
};
