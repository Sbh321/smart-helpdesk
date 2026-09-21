<?php

declare(strict_types=1);

namespace App\Modules\Automation\Queries;

use App\Modules\Automation\Domain\Duplicates\DuplicateSettings;
use App\Modules\Automation\Domain\Duplicates\TicketText;
use App\Modules\Tickets\Models\Ticket;
use App\Support\Time\Clock;

final readonly class DuplicateCandidates
{
    public function __construct(private Clock $clock) {}

    /** @return list<TicketText> */
    public function for(TicketText $ticket, DuplicateSettings $settings): array
    {
        return Ticket::query()
            ->select(['id', 'title', 'description', 'created_at'])
            ->where('status', '<>', 'closed')
            ->where('created_at', '>=', $this->clock->now()->subDays($settings->windowDays))
            ->where('id', '<>', $ticket->id)
            // Ranked, not filtered: a `title % ?` filter would use the GIN index but drop tickets that
            // share words only in the description. The scan is bounded by the tenant's 30-day window
            // through tickets_tenant_created_idx, and similarity() on a title costs microseconds.
            ->orderByRaw('similarity(title, ?) DESC', [$ticket->title])
            ->orderByDesc('created_at')->orderBy('id')
            ->limit($settings->candidateLimit)->get()
            ->map(fn (Ticket $candidate): TicketText => new TicketText(
                $candidate->id, $candidate->title, $candidate->description, $candidate->created_at,
            ))->all();
    }
}
