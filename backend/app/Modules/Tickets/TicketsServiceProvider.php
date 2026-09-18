<?php

declare(strict_types=1);

namespace App\Modules\Tickets;

use App\Modules\Tickets\Console\SeedSampleTicketsCommand;
use App\Modules\Tickets\Models\Ticket;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Database\Eloquent\Relations\Relation;

final class TicketsServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        Relation::morphMap(['ticket' => Ticket::class]);

        if ($this->app->runningInConsole() && ! $this->app->isProduction()) {
            $this->commands([SeedSampleTicketsCommand::class]);
        }
    }
}
