<?php

declare(strict_types=1);

namespace App\Modules\Reporting;

use App\Modules\Reporting\Console\Experiments\HistoryExperiment;
use App\Modules\Reporting\Console\RebuildReports;
use App\Modules\Reporting\Console\SnapshotDaily;
use App\Modules\Reporting\Console\VerifyReports;
use App\Modules\Reporting\Listeners\QueueTicketReportRefresh;
use App\Modules\Sla\Events\SlaBreached;
use App\Modules\Sla\Events\SlaWarning;
use App\Modules\Tenancy\Bootstrappers\RlsTenancyBootstrapper;
use App\Modules\Tickets\Events\CommentAdded;
use App\Modules\Tickets\Events\FirstPublicReplyRecorded;
use App\Modules\Tickets\Events\PriorityChanged;
use App\Modules\Tickets\Events\TicketAssigned;
use App\Modules\Tickets\Events\TicketCreated;
use App\Modules\Tickets\Events\TicketLifecycleChanged;
use App\Modules\Tickets\Events\TicketStatusChanged;
use App\Modules\Tickets\Events\TicketUpdated;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schedule;

final class ReportingServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        // Experiment E6 for `experiment:run` (docs/12-academic/result-analysis-plan.md).
        $this->app->tag([HistoryExperiment::class], 'experiments');
    }

    protected function bootModule(): void
    {
        // Change capture reads app.actor_* from the PostgreSQL session (ADR-0022 §1). Tenancy is
        // initialised before `auth:sanctum,api` runs, so the actor is only known once a guard
        // resolves its principal. EnsureTenantMembership refreshes it for every authenticated
        // tenant request (users, Sanctum tokens and API clients as `api_client`); this listener
        // covers sign-in inside the pre-authentication group. Jobs, commands and the scheduler
        // never fire either and stay `system`.
        Event::listen(Authenticated::class, function (): void {
            $this->app->make(RlsTenancyBootstrapper::class)->refreshActor();
        });

        // Every ticket domain event refreshes that ticket's intervals and facts (reporting.md §Operations).
        Event::listen([
            TicketCreated::class, TicketUpdated::class, TicketStatusChanged::class, TicketAssigned::class,
            PriorityChanged::class, CommentAdded::class, TicketLifecycleChanged::class,
            FirstPublicReplyRecorded::class, SlaWarning::class, SlaBreached::class,
        ], QueueTicketReportRefresh::class);

        if ($this->app->runningInConsole()) {
            $this->commands([SnapshotDaily::class, RebuildReports::class, VerifyReports::class]);
            // Hourly: each workspace gets yesterday's snapshot soon after its own local midnight.
            Schedule::command('reports:snapshot-daily')->hourlyAt(20)->onOneServer()->withoutOverlapping();
        }
    }
}
