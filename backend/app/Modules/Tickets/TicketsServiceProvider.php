<?php

declare(strict_types=1);

namespace App\Modules\Tickets;

use App\Modules\Tenancy\Settings\ArraySection;
use App\Modules\Tenancy\Settings\SettingsRegistry;
use App\Modules\Tickets\Console\AutoCloseResolvedTickets;
use App\Modules\Tickets\Console\SeedSampleTicketsCommand;
use App\Modules\Tickets\Models\Ticket;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Schedule;

final class TicketsServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        $this->app->make(SettingsRegistry::class)->register(ArraySection::fromConfig('tickets', [
            'auto_close_days' => ['required', 'integer', 'between:1,90'],
            'reopen_window_days' => ['required', 'integer', 'between:0,365'],
        ]));

        Relation::morphMap(['ticket' => Ticket::class]);

        if ($this->app->runningInConsole()) {
            $this->commands([AutoCloseResolvedTickets::class]);
            Schedule::command('tickets:auto-close')->dailyAt('03:10')->onOneServer()->withoutOverlapping();

            if (! $this->app->isProduction()) {
                $this->commands([SeedSampleTicketsCommand::class]);
            }
        }
    }
}
