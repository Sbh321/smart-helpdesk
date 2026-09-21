<?php

declare(strict_types=1);

namespace App\Modules\Sla;

use App\Modules\Sla\Console\EvaluateSla;
use App\Modules\Sla\Console\Experiments\SlaExperiment;
use App\Modules\Sla\Contracts\SlaStrategy;
use App\Modules\Sla\Listeners\AdvanceSlaOnTicketLifecycle;
use App\Modules\Sla\Listeners\MeetFirstResponseOnReply;
use App\Modules\Sla\Listeners\StartSlaOnTicketCreated;
use App\Modules\Sla\Support\SlaStrategyFactory;
use App\Modules\Tenancy\Settings\ArraySection;
use App\Modules\Tenancy\Settings\SettingsRegistry;
use App\Modules\Tickets\Events\FirstPublicReplyRecorded;
use App\Modules\Tickets\Events\TicketCreated;
use App\Modules\Tickets\Events\TicketLifecycleChanged;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schedule;

final class SlaServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SlaStrategy::class, function ($app): SlaStrategy {
            return $app->make(SlaStrategyFactory::class)
                ->forWarningFraction((float) config('helpdesk.sla.warning_fraction', 0.75));
        });
        // Experiment E4 for `experiment:run` (docs/12-academic/result-analysis-plan.md).
        $this->app->tag([SlaExperiment::class], 'experiments');
    }

    protected function bootModule(): void
    {
        $this->app->make(SettingsRegistry::class)->register(ArraySection::fromConfig('sla', [
            // The fraction a new policy starts with; every policy then carries its own.
            'warning_fraction' => ['required', 'numeric', 'between:0.10,0.95'],
            'first_response_applies_to_agent_created' => ['required', 'boolean'],
        ]));

        Event::listen(TicketCreated::class, StartSlaOnTicketCreated::class);
        Event::listen(TicketLifecycleChanged::class, AdvanceSlaOnTicketLifecycle::class);
        Event::listen(FirstPublicReplyRecorded::class, MeetFirstResponseOnReply::class);

        if ($this->app->runningInConsole()) {
            $this->commands([EvaluateSla::class]);
            // docs/11-operations/scheduler.md: deadlines keep being checked during `schedule:pause`.
            Schedule::command('sla:evaluate')->everyMinute()->onOneServer()->withoutOverlapping()
                ->runInBackground()->evenWhenPaused();
        }
    }
}
