<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Experiments\RunExperiments;
use App\Support\Health\HealthChecks;
use App\Support\Logging\LogContext;
use App\Support\Time\Clock;
use App\Support\Time\SystemClock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
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
        HealthChecks::register();

        if ($this->app->runningInConsole()) {
            // Modules tag their experiments with `experiments` (docs/12-academic/result-analysis-plan.md).
            $this->commands([RunExperiments::class]);
        }
    }
}
