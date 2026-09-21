<?php

declare(strict_types=1);

namespace App\Modules\Tenancy;

use App\Modules\Tenancy\Bootstrappers\RlsTenancyBootstrapper;
use App\Modules\Tenancy\Settings\ArraySection;
use App\Modules\Tenancy\Settings\Sections\BrandingSection;
use App\Modules\Tenancy\Settings\Sections\GeneralSection;
use App\Modules\Tenancy\Settings\SettingsRegistry;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;

/**
 * Wires stancl/tenancy's lifecycle (the package's published provider stub is not used).
 */
final class TenancyServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        // One registry for the process; every module adds its own sections while booting.
        $this->app->singleton(SettingsRegistry::class);
    }

    protected function bootModule(): void
    {
        $settings = $this->app->make(SettingsRegistry::class);
        $settings->register(new GeneralSection);
        $settings->register(new BrandingSection);
        $settings->register(ArraySection::fromConfig('features', ['realtime' => ['required', 'boolean'], 'exports' => ['required', 'boolean']]));

        Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
        Event::listen(TenancyEnded::class, RevertToCentralContext::class);

        Event::listen(function (ConnectionEstablished $event): void {
            $this->app->make(RlsTenancyBootstrapper::class)->reapply($event->connection);
        });
    }
}
