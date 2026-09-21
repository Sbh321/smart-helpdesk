<?php

declare(strict_types=1);

namespace App\Modules\Automation\Console;

use App\Modules\Automation\Actions\ScoreTicketPriority;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Console\Command;
use Laravel\Telescope\Telescope;

/**
 * Hourly ageing pass (docs/05-algorithms/priority-scoring.md §When it runs): waiting time grows,
 * so every unfinished ticket is scored again. Linear in the number of active tickets.
 */
final class ReevaluatePriority extends Command
{
    protected $signature = 'tickets:reevaluate-priority {--tenant= : Limit to a workspace slug}';

    protected $description = 'Recalculate priority for active tickets as their age grows';

    public function handle(ScoreTicketPriority $score): int
    {
        if (class_exists(Telescope::class)) {
            Telescope::stopRecording();
        }

        $tenants = Tenant::active();
        if (is_string($slug = $this->option('tenant')) && $slug !== '') {
            $tenants->where('slug', $slug);
        }

        $count = 0;
        foreach ($tenants->cursor() as $tenant) {
            $count += (int) $tenant->run(function () use ($score): int {
                $scored = 0;
                $calendar = $score->ageCalendar(); // once per workspace, not per ticket

                Ticket::query()->whereIn('status', TicketStatus::active())
                    ->chunkById(500, function ($tickets) use ($score, $calendar, &$scored): void {
                        foreach ($tickets as $ticket) {
                            $score($ticket, ageCalendar: $calendar);
                            $scored++;
                        }
                    });

                return $scored;
            });
        }

        $this->components->info("Reevaluated {$count} active ticket priorities.");

        return self::SUCCESS;
    }
}
