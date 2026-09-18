<?php

declare(strict_types=1);

namespace App\Modules\Platform;

use App\Modules\Platform\Console\CreatePlatformAdminCommand;
use App\Modules\Platform\Console\CreateTenantCommand;
use App\Support\Modules\ModuleServiceProvider;

final class PlatformServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([CreateTenantCommand::class, CreatePlatformAdminCommand::class]);
        }
    }
}
