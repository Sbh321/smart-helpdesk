<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX sla_policies_one_per_tier ON sla_policies (tenant_id, applies_to_tier) WHERE applies_to_tier IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX sla_policies_one_per_tier');
    }
};
