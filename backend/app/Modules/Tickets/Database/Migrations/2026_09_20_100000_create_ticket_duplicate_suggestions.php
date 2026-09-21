<?php

declare(strict_types=1);

use App\Modules\Reporting\Support\ReportableTables;
use App\Modules\Tenancy\Support\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_duplicate_suggestions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('ticket_id');
            $table->uuid('candidate_ticket_id');
            $table->decimal('score', 6, 4);
            $table->jsonb('breakdown')->default('{}');
            $table->string('decision', 12)->default('pending');
            $table->uuid('decided_by_user_id')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'ticket_id', 'candidate_ticket_id'], 'ticket_duplicate_pair_key');
            $table->foreign(['tenant_id', 'ticket_id'], 'ticket_duplicate_ticket_fk')
                ->references(['tenant_id', 'id'])->on('tickets')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'candidate_ticket_id'], 'ticket_duplicate_candidate_fk')
                ->references(['tenant_id', 'id'])->on('tickets')->cascadeOnDelete();
            $table->index(['tenant_id', 'candidate_ticket_id'], 'ticket_duplicate_candidate_idx');
        });
        TenantTables::nullableForeign('ticket_duplicate_suggestions', 'decided_by_user_id', 'users', 'ticket_duplicate_decider_fk');
        DB::statement('ALTER TABLE ticket_duplicate_suggestions ADD CONSTRAINT ticket_duplicate_score_check CHECK (score BETWEEN 0 AND 1)');
        DB::statement("ALTER TABLE ticket_duplicate_suggestions ADD CONSTRAINT ticket_duplicate_decision_check CHECK (decision IN ('pending', 'accepted', 'dismissed'))");
        DB::statement('ALTER TABLE ticket_duplicate_suggestions ADD CONSTRAINT ticket_duplicate_not_self_check CHECK (ticket_id <> candidate_ticket_id)');
        TenantTables::protectTenantId('ticket_duplicate_suggestions');
        ReportableTables::captureChanges('ticket_duplicate_suggestions');
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_duplicate_suggestions');
    }
};
