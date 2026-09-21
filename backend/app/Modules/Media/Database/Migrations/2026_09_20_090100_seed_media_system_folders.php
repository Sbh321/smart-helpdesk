<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            foreach (['tickets' => 'Tickets', 'email' => 'Email', 'branding' => 'Branding'] as $key => $name) {
                DB::table('media_folders')->insertOrIgnore([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'parent_id' => null,
                    'name' => $name,
                    'system_key' => $key,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('media_folders')->whereIn('system_key', ['tickets', 'email', 'branding'])->delete();
    }
};
