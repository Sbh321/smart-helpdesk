<?php

declare(strict_types=1);

namespace App\Modules\Automation\Listeners;

use App\Modules\Automation\Actions\PersistDuplicateSuggestions;
use App\Modules\Automation\Actions\SuggestDuplicates;
use App\Modules\Automation\Domain\Duplicates\TicketText;
use App\Modules\Tickets\Events\TicketCreated;
use App\Modules\Tickets\Models\Ticket;

/**
 * Records duplicate suggestions in the creation transaction. Bounded work: at most
 * `candidate_limit` candidates are compared (docs/05-algorithms/duplicate-detection.md).
 */
final readonly class SuggestDuplicatesOnTicketCreated
{
    public function __construct(
        private SuggestDuplicates $suggest,
        private PersistDuplicateSuggestions $persist,
    ) {}

    public function handle(TicketCreated $event): void
    {
        $ticket = Ticket::query()->findOrFail($event->ticketId);

        ($this->persist)($ticket, ($this->suggest)(new TicketText(
            $ticket->id, $ticket->title, $ticket->description, $ticket->created_at,
        )));
    }
}
