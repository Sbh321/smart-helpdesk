<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Console;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Settings\Settings;
use App\Modules\Tickets\Actions\AutoCloseTicket;
use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Models\Ticket;
use App\Support\Time\Clock;
use Illuminate\Console\Command;
use Throwable;

/**
 * Daily: closes tickets resolved more than `tickets.auto_close_days` ago, per workspace and with that
 * workspace's setting (docs/11-operations/scheduler.md). One failing ticket or workspace is reported
 * and skipped; the next run is the retry.
 */
final class AutoCloseResolvedTickets extends Command
{
    protected $signature = 'tickets:auto-close {--tenant= : Limit to a workspace slug}';

    protected $description = 'Close resolved tickets whose resolution period has passed';

    public function handle(AutoCloseTicket $close, Settings $settings, Clock $clock): int
    {
        $tenants = Tenant::active();
        if (is_string($slug = $this->option('tenant')) && $slug !== '') {
            $tenants->where('slug', $slug);
        }

        $closed = 0;
        foreach ($tenants->cursor() as $tenant) {
            try {
                $closed += (int) $tenant->run(function () use ($close, $settings, $clock): int {
                    $before = $clock->now()->subDays(max(1, (int) $settings->get('tickets.auto_close_days', 7)));
                    $count = 0;

                    Ticket::query()->where('status', TicketStatus::Resolved->value)
                        ->where('resolved_at', '<=', $before)
                        ->chunkById(500, function ($tickets) use ($close, $before, &$count): void {
                            foreach ($tickets as $ticket) {
                                try {
                                    $count += $close($ticket->id, $before) ? 1 : 0;
                                } catch (Throwable $exception) {
                                    report($exception);
                                }
                            }
                        });

                    return $count;
                });
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $this->components->info("Closed {$closed} resolved tickets.");

        return self::SUCCESS;
    }
}
