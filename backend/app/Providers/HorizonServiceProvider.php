<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Platform\Support\PlatformPass;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon has its own light/dark/system switch in its top bar and follows the system theme.
        // The pass decides in every environment, local included (Horizon otherwise opens up in local).
        Horizon::auth(fn ($request): bool => Gate::check('viewHorizon'));

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        // Platform super admins holding the platform pass of the monitor host (ADR-0024); the proxy checks
        // the same pass before the request reaches Horizon.
        Gate::define('viewHorizon', fn ($user = null): bool => app(PlatformPass::class)
            ->check(request()->cookie(PlatformPass::cookieName())) !== null);
    }
}
