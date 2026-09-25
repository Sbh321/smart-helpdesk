<?php

declare(strict_types=1);

namespace App\Modules\Platform;

use App\Modules\Billing\Events\PaymentSubmitted;
use App\Modules\Platform\Console\CreatePlatformAdminCommand;
use App\Modules\Platform\Console\CreateTenantCommand;
use App\Modules\Platform\Http\Controllers\MonitorHomeController;
use App\Modules\Platform\Http\Middleware\RequirePlatformPass;
use App\Modules\Platform\Listeners\NotifyAdminsOfPayment;
use App\Modules\Platform\Support\PlatformPass;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Spatie\Health\Http\Controllers\HealthCheckResultsController;

final class PlatformServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        Event::listen(PaymentSubmitted::class, NotifyAdminsOfPayment::class);

        // Self sign-up (ADR-0025 §8): a few requests per hour per client and per address.
        RateLimiter::for('signup', fn (Request $request): array => [
            Limit::perHour(5)->by('signup-ip:'.$request->ip()),
            Limit::perHour(3)->by('signup-email:'.strtolower($request->string('email')->value())),
        ]);

        // The platform-only hosts (ADR-0024): cookies only, no session, no tenant. Split layout only.
        // MVP-SHORTCUT: single-host installs have no platform pass, so Horizon there refuses everyone and the
        // platform documentation is not served; V1: the platform pass on paths of the one host (V1-PL-17).
        if (config('helpdesk.host_layout') === 'split' && ! $this->app->routesAreCached()) {
            foreach (PlatformPass::TARGETS as $target => $hostKey) {
                Route::middleware([EncryptCookies::class, AddQueuedCookiesToResponse::class])
                    ->domain(PlatformPass::host($target))
                    ->group(__DIR__.'/Routes/platform-hosts.php');
            }
            // The monitor host's own pages, the start page and the health dashboard; the proxy and this
            // middleware both require the pass.
            Route::middleware([EncryptCookies::class, RequirePlatformPass::class])
                ->domain(PlatformPass::host('monitor'))
                ->group(function (): void {
                    Route::get('/', MonitorHomeController::class)->name('monitor.home');
                    Route::get('/health', HealthCheckResultsController::class)->name('monitor.health');
                });
        }

        if ($this->app->runningInConsole()) {
            $this->commands([CreateTenantCommand::class, CreatePlatformAdminCommand::class]);
        }
    }
}
