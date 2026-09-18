<?php

declare(strict_types=1);

namespace App\Modules\Tenancy;

use App\Modules\Tenancy\Bootstrappers\RlsTenancyBootstrapper;
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
    protected function bootModule(): void
    {
        Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
        Event::listen(TenancyEnded::class, RevertToCentralContext::class);

        Event::listen(function (ConnectionEstablished $event): void {
            $this->app->make(RlsTenancyBootstrapper::class)->reapply($event->connection);
        });
    }
}
