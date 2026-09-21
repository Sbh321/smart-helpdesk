<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class TicketStatusChanged implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $tenantId,
        public string $ticketId,
        public ?string $actorId,
        public string $from,
        public string $to,
    ) {}
}
