<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Platform\Support\PlatformPass;
use App\Support\Experiments\RunExperiments;
use App\Support\Health\HealthChecks;
use App\Support\Logging\LogContext;
use App\Support\Time\Clock;
use App\Support\Time\SystemClock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Clock::class, SystemClock::class);

        // Telescope is a development-only tool: it records cross-tenant data (ADR-0012, docs/11-operations/observability.md).
        if ($this->app->environment('local') && class_exists(TelescopeServiceProvider::class)) {
            $this->app->register(TelescopeServiceProvider::class);
        }
    }

    public function boot(): void
    {
        // Surface lazy loading, silently discarded attributes and missing attributes during development and tests.
        Model::shouldBeStrict(! $this->app->isProduction());

        LogContext::register($this->app->make('events'));

        if (class_exists(Telescope::class) && $this->app->providerIsLoaded(TelescopeServiceProvider::class)) {
            // Dark theme, and only for platform super admins holding the monitor host's pass (ADR-0024),
            // even in local, where Telescope would otherwise let everyone in.
            Telescope::night();
            Telescope::auth(fn ($request): bool => app(PlatformPass::class)
                ->check($request->cookie(PlatformPass::cookieName())) !== null);
        }
        HealthChecks::register();

        if ($this->app->runningInConsole()) {
            // Modules tag their experiments with `experiments` (docs/12-academic/result-analysis-plan.md).
            $this->commands([RunExperiments::class]);
        }
    }
}
