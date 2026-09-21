<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Jobs;

use App\Modules\Reporting\Support\TicketReportWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Recomputes one ticket's intervals and facts after a domain event (`reports:refresh-ticket` in
 * reporting.md §Operations). Idempotent, so a retry is harmless. While one refresh of a ticket waits in
 * the queue, further events for it add nothing (it reads the latest state when it runs); once it
 * starts, the next event queues a new one, so no change is missed.
 */
final class RefreshTicketReport implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30];

    public int $uniqueFor = 300;

    public function __construct(public readonly string $ticketId)
    {
        $this->onQueue('reports');
    }

    public function uniqueId(): string
    {
        return (string) tenant()?->getTenantKey().':'.$this->ticketId;
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['reports', 'tenant:'.tenant()?->getTenantKey(), 'ticket:'.$this->ticketId];
    }

    public function handle(TicketReportWriter $writer): void
    {
        $writer->refresh($this->ticketId);
    }
}
