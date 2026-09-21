<?php

declare(strict_types=1);

namespace App\Modules\Agents;

use App\Modules\Tenancy\Settings\ArraySection;
use App\Modules\Tenancy\Settings\SettingsRegistry;
use App\Support\Modules\ModuleServiceProvider;

/**
 * The Agent directory. Workload counting, the assignment candidate loader and the
 * `agents:reconcile-workload` command live in Automation, which may depend on Tickets; this
 * module asks about tickets only through `Contracts\DirectoryUsage`.
 */
final class AgentsServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        // Whether an Agent must be on shift to be assigned a Ticket.
        $this->app->make(SettingsRegistry::class)->register(ArraySection::fromConfig('shifts', [
            'enforce' => ['required', 'boolean'],
        ]));
    }
}
