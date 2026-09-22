<?php

declare(strict_types=1);

namespace App\Modules\Demo;

use App\Modules\Demo\Console\DemoResetCommand;
use App\Modules\Demo\Console\DemoTickCommand;
use App\Support\Modules\ModuleServiceProvider;

/**
 * The demo dataset and its commands (roadmap/11-demo-dataset.md, M3-13). No routes, models or tables:
 * the module drives the other modules' actions to build the `acme` and `globex` workspaces.
 */
final class DemoServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([DemoResetCommand::class, DemoTickCommand::class]);
        }
    }
}
