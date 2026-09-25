<?php

declare(strict_types=1);

namespace App\Support\Modules;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use ReflectionClass;

/**
 * Base provider for a module under app/Modules/<Module>.
 *
 * Loads the module's migrations and API routes when they exist, so a module only
 * needs its own provider class (docs/03-architecture/backend.md).
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $directory = dirname((string) (new ReflectionClass(static::class))->getFileName());

        if (is_dir($directory.'/Database/Migrations')) {
            $this->loadMigrationsFrom($directory.'/Database/Migrations');
        }

        if (! $this->app->routesAreCached()) {
            $this->loadModuleRoutes($directory);
        }

        $this->bootModule();
    }

    /**
     * Route files per module (docs/03-architecture/tenancy.md §Tenant resolution):
     *  - Routes/api.php      authenticated tenant API on the api host under /v1
     *  - Routes/public.php   pre-authentication tenant API (workspace in the body) under /v1
     *  - Routes/central.php  pre-authentication API that belongs to no workspace (the workspace finder, M5-02) under /v1
     *  - Routes/platform.php platform API on the admin host under /platform-api
     * Hosts are only bound in the split layout; single-host installs strip /api at the proxy.
     */
    private function loadModuleRoutes(string $directory): void
    {
        $files = [
            'api.php' => ['prefix' => 'v1', 'middleware' => ['api', 'tenant'], 'host' => 'api'],
            'public.php' => ['prefix' => 'v1', 'middleware' => ['api', 'tenant.guest'], 'host' => 'api'],
            'central.php' => ['prefix' => 'v1', 'middleware' => ['api'], 'host' => 'api'],
            'platform.php' => ['prefix' => 'platform-api', 'middleware' => ['api', 'platform'], 'host' => 'admin'],
        ];

        foreach ($files as $file => $group) {
            if (! is_file("{$directory}/Routes/{$file}")) {
                continue;
            }

            $route = Route::middleware($group['middleware'])->prefix($group['prefix']);

            if (config('helpdesk.host_layout') === 'split') {
                $route->domain((string) config("helpdesk.hosts.{$group['host']}"));
            }

            $route->group("{$directory}/Routes/{$file}");
        }
    }

    /**
     * Module-specific boot logic (policies, listeners, schedules).
     */
    protected function bootModule(): void {}
}
