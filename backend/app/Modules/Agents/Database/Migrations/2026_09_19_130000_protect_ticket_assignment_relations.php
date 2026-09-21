<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE ticket_assignments ADD CONSTRAINT ticket_assignments_team_fk FOREIGN KEY (tenant_id, team_id) REFERENCES teams (tenant_id, id) ON DELETE SET NULL (team_id)');
        DB::statement('ALTER TABLE ticket_assignments ADD CONSTRAINT ticket_assignments_agent_fk FOREIGN KEY (tenant_id, agent_profile_id) REFERENCES agent_profiles (tenant_id, id) ON DELETE SET NULL (agent_profile_id)');
        DB::statement('ALTER TABLE ticket_assignments ADD CONSTRAINT ticket_assignments_previous_agent_fk FOREIGN KEY (tenant_id, previous_agent_profile_id) REFERENCES agent_profiles (tenant_id, id) ON DELETE SET NULL (previous_agent_profile_id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ticket_assignments DROP CONSTRAINT ticket_assignments_previous_agent_fk');
        DB::statement('ALTER TABLE ticket_assignments DROP CONSTRAINT ticket_assignments_agent_fk');
        DB::statement('ALTER TABLE ticket_assignments DROP CONSTRAINT ticket_assignments_team_fk');
    }
};
