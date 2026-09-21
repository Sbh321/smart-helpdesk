<?php

declare(strict_types=1);

namespace App\Modules\Automation\Listeners;

use App\Modules\Automation\Actions\ScoreTicketPriority;
use App\Modules\Tickets\Events\TicketPriorityInputsChanged;
use App\Modules\Tickets\Models\Ticket;

/**
 * Scores a ticket inside the transaction that created or edited it. Bounded work: one strategy
 * call on one row (docs/03-architecture/backend.md §Events / listeners).
 */
final readonly class ScorePriorityOnTicketInputs
{
    public function __construct(private ScoreTicketPriority $score) {}

    public function handle(TicketPriorityInputsChanged $event): void
    {
        ($this->score)(Ticket::query()->findOrFail($event->ticketId), $event->actorId, ! $event->initial);
    }
}
