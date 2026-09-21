<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE ticket_sla_timers ADD COLUMN warning_fraction numeric(3,2)');
        DB::statement('UPDATE ticket_sla_timers AS timer SET warning_fraction = policy.warning_fraction FROM sla_policies AS policy WHERE timer.tenant_id = policy.tenant_id AND timer.policy_id = policy.id');
        DB::statement('ALTER TABLE ticket_sla_timers ALTER COLUMN warning_fraction SET NOT NULL');
        DB::statement('ALTER TABLE ticket_sla_timers ADD CONSTRAINT ticket_sla_timers_warning_fraction_check CHECK (warning_fraction BETWEEN 0.10 AND 0.95)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ticket_sla_timers DROP CONSTRAINT ticket_sla_timers_warning_fraction_check');
        DB::statement('ALTER TABLE ticket_sla_timers DROP COLUMN warning_fraction');
    }
};
