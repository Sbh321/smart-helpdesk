<?php

declare(strict_types=1);

namespace App\Modules\Platform;

use App\Modules\Platform\Console\CreatePlatformAdminCommand;
use App\Modules\Platform\Console\CreateTenantCommand;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Support\Facades\Route;

final class PlatformServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        // The platform-docs host (ADR-0024): cookies only, no session, no tenant. Split layout only.
        // MVP-SHORTCUT: single-host installs do not serve the platform documentation; V1: a path on the one host (V1-PL-17).
        if (config('helpdesk.host_layout') === 'split' && ! $this->app->routesAreCached()) {
            Route::middleware([EncryptCookies::class, AddQueuedCookiesToResponse::class])
                ->domain((string) config('helpdesk.hosts.platform_docs'))
                ->group(__DIR__.'/Routes/platform-docs.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([CreateTenantCommand::class, CreatePlatformAdminCommand::class]);
        }
    }
}
