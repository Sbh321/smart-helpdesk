<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Identity\Actions\SyncPermissionCatalogue;
use Illuminate\Database\Seeder;

/**
 * Production defaults only. Demo data lives in DemoSeeder (roadmap M2).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // The permission catalogue and the global default roles are platform data, not demo data.
        app(SyncPermissionCatalogue::class)();
    }
}
