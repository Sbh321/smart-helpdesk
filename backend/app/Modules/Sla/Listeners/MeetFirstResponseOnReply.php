<?php

declare(strict_types=1);

namespace App\Modules\Sla\Listeners;

use App\Modules\Sla\Actions\CompleteFirstResponse;
use App\Modules\Tickets\Events\FirstPublicReplyRecorded;
use App\Modules\Tickets\Models\Ticket;

/**
 * Meets the first-response timer inside the comment transaction.
 */
final readonly class MeetFirstResponseOnReply
{
    public function __construct(private CompleteFirstResponse $complete) {}

    public function handle(FirstPublicReplyRecorded $event): void
    {
        ($this->complete)(Ticket::query()->findOrFail($event->ticketId));
    }
}
