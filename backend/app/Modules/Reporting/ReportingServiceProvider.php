<?php

declare(strict_types=1);

namespace App\Modules\Reporting;

use App\Modules\Tenancy\Bootstrappers\RlsTenancyBootstrapper;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Support\Facades\Event;

final class ReportingServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        // Change capture reads app.actor_* from the PostgreSQL session (ADR-0022 §1). Tenancy is
        // initialised before `auth:sanctum` runs, so the actor is only known once a guard resolves
        // its user; jobs, commands and the scheduler never fire this and stay `system`.
        // MVP-SHORTCUT: bearer-token requests resolve through a RequestGuard, which fires no
        // Authenticated event, so they record `system`; V1: M3-04 gives API clients the
        // `api_client` actor type when Passport client credentials arrive.
        Event::listen(Authenticated::class, function (): void {
            $this->app->make(RlsTenancyBootstrapper::class)->refreshActor();
        });
    }
}
