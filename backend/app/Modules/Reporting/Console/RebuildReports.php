<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Console;

use App\Modules\Reporting\Support\DailySnapshotBuilder;
use App\Modules\Reporting\Support\TicketReportWriter;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tickets\Models\Ticket;
use App\Support\Time\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Laravel\Telescope\Telescope;

/**
 * Recomputes the read models of a workspace from its history: every ticket's intervals and facts, then
 * a snapshot for every day from `--from` (default: the first ticket's day) to yesterday. Idempotent:
 * running it twice, or after the incremental jobs, gives the same rows.
 */
final class RebuildReports extends Command
{
    protected $signature = 'reports:rebuild {--tenant= : Limit to a workspace slug} {--from= : First snapshot day, YYYY-MM-DD}';

    protected $description = 'Recompute ticket intervals, facts and daily snapshots from history';

    public function handle(TicketReportWriter $writer, DailySnapshotBuilder $snapshots, Clock $clock): int
    {
        if (class_exists(Telescope::class)) {
            Telescope::stopRecording();
        }

        $tenants = Tenant::active();
        if (is_string($slug = $this->option('tenant')) && $slug !== '') {
            $tenants->where('slug', $slug);
        }

        foreach ($tenants->cursor() as $tenant) {
            [$tickets, $days] = $tenant->run(function () use ($tenant, $writer, $snapshots, $clock): array {
                $tickets = 0;
                Ticket::query()->select('id')->chunkById(500, function ($chunk) use ($writer, &$tickets): void {
                    foreach ($chunk as $ticket) {
                        $writer->refresh($ticket->id);
                        $tickets++;
                    }
                });

                $timezone = (string) ($tenant->timezone ?: 'UTC');
                $first = is_string($from = $this->option('from')) && $from !== ''
                    ? CarbonImmutable::parse($from, $timezone)
                    : Ticket::query()->min('created_at');
                $days = 0;
                if ($first !== null) {
                    $day = CarbonImmutable::parse($first)->setTimezone($timezone)->startOfDay();
                    $yesterday = $clock->now()->setTimezone($timezone)->subDay()->startOfDay();
                    for (; $day <= $yesterday; $day = $day->addDay()) {
                        $snapshots->write((string) $tenant->id, $day, $timezone, $clock->now());
                        $days++;
                    }
                }

                return [$tickets, $days];
            });
            $this->components->twoColumnDetail((string) $tenant->slug, "{$tickets} tickets, {$days} days");
        }

        return self::SUCCESS;
    }
}
