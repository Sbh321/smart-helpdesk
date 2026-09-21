<?php

declare(strict_types=1);

namespace App\Modules\Automation;

use App\Modules\Agents\Contracts\DirectoryUsage;
use App\Modules\Automation\Console\Experiments\AssignmentExperiment;
use App\Modules\Automation\Console\Experiments\DuplicateExperiment;
use App\Modules\Automation\Console\Experiments\GenerateDuplicates;
use App\Modules\Automation\Console\Experiments\GenerateWorkload;
use App\Modules\Automation\Console\Experiments\PriorityExperiment;
use App\Modules\Automation\Console\ReconcileAgentWorkload;
use App\Modules\Automation\Console\ReevaluatePriority;
use App\Modules\Automation\Contracts\AssignmentStrategy;
use App\Modules\Automation\Contracts\DuplicateStrategy;
use App\Modules\Automation\Contracts\PriorityStrategy;
use App\Modules\Automation\Listeners\AdjustWorkloadOnTicketLifecycle;
use App\Modules\Automation\Listeners\AutoAssignOnTicketCreated;
use App\Modules\Automation\Listeners\ScorePriorityOnTicketInputs;
use App\Modules\Automation\Listeners\SuggestDuplicatesOnTicketCreated;
use App\Modules\Automation\Support\AutomationSettings;
use App\Modules\Automation\Support\TicketDirectoryUsage;
use App\Modules\Automation\Support\WorkspaceDuplicateStrategy;
use App\Modules\Automation\Support\WorkspacePriorityStrategy;
use App\Modules\Tenancy\Settings\SettingsRegistry;
use App\Modules\Tickets\Events\TicketCreated;
use App\Modules\Tickets\Events\TicketLifecycleChanged;
use App\Modules\Tickets\Events\TicketPriorityInputsChanged;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schedule;
use LogicException;

final class AutomationServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        // Agents asks about tickets through this contract so that it never imports Tickets.
        $this->app->bind(DirectoryUsage::class, TicketDirectoryUsage::class);
        // Both wrappers build the configured strategy with the current workspace's settings per call.
        $this->app->bind(DuplicateStrategy::class, WorkspaceDuplicateStrategy::class);
        $this->app->bind(AssignmentStrategy::class, function ($app): AssignmentStrategy {
            $configured = config('helpdesk.strategies.assignment');
            if (! is_string($configured) || ! is_subclass_of($configured, AssignmentStrategy::class)) {
                throw new LogicException('The configured assignment strategy must implement AssignmentStrategy.');
            }

            /** @var class-string<AssignmentStrategy> $configured */
            return $app->make($configured);
        });
        $this->app->bind(PriorityStrategy::class, WorkspacePriorityStrategy::class);
        // Experiments E1–E3 for `experiment:run` (docs/12-academic/result-analysis-plan.md).
        $this->app->tag([AssignmentExperiment::class, DuplicateExperiment::class, PriorityExperiment::class], 'experiments');
    }

    protected function bootModule(): void
    {
        AutomationSettings::register($this->app->make(SettingsRegistry::class));

        // Synchronous hooks fired inside the ticket transaction (docs/03-architecture/backend.md
        // §Events / listeners: bounded Automation work on ticket creation). SuggestDuplicates runs
        // before AutoAssign only by registration order; neither depends on the other.
        Event::listen(TicketPriorityInputsChanged::class, ScorePriorityOnTicketInputs::class);
        Event::listen(TicketCreated::class, SuggestDuplicatesOnTicketCreated::class);
        Event::listen(TicketCreated::class, AutoAssignOnTicketCreated::class);
        // Stored agent workload follows resolve, close and reopen, inside the same transaction.
        Event::listen(TicketLifecycleChanged::class, AdjustWorkloadOnTicketLifecycle::class);

        // Thirty duplicate previews a minute per user (docs/07-api/conventions.md §Rate limits).
        RateLimiter::for('duplicate-preview', fn (Request $request): Limit => Limit::perMinute(30)
            ->by('duplicate-preview:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        if ($this->app->runningInConsole()) {
            $this->commands([ReevaluatePriority::class, ReconcileAgentWorkload::class, GenerateWorkload::class, GenerateDuplicates::class]);
            Schedule::command('tickets:reevaluate-priority')->hourlyAt(5)->onOneServer()->withoutOverlapping();
            Schedule::command('agents:reconcile-workload')->dailyAt('03:30')->onOneServer()->withoutOverlapping();
        }
    }
}
