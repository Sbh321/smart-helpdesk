<?php

declare(strict_types=1);

namespace App\Modules\Identity\Console;

use App\Modules\Identity\Actions\SyncPermissionCatalogue;
use Illuminate\Console\Command;

final class SyncPermissionsCommand extends Command
{
    protected $signature = 'identity:sync-permissions';

    protected $description = 'Write the permission catalogue and the global default roles (idempotent)';

    public function handle(SyncPermissionCatalogue $sync): int
    {
        $result = $sync();

        $this->components->info("Synced {$result['permissions']} permissions and {$result['roles']} default roles.");

        return self::SUCCESS;
    }
}
