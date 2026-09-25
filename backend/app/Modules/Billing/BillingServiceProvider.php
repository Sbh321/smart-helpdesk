<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Modules\Billing\Console\RemindSubscriptions;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Support\Facades\Schedule;

/**
 * Plans, subscriptions and receipt payments (ADR-0025). Never imports the Platform module: it
 * announces what happened through events, and Platform tells the admins.
 */
final class BillingServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([RemindSubscriptions::class]);
            Schedule::command('billing:remind')->dailyAt('06:00')->onOneServer()->withoutOverlapping();
        }
    }
}
