<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class TicketUpdated implements ShouldDispatchAfterCommit
{
    /**
     * @param  array<string, mixed>  $changes
     */
    public function __construct(
        public string $tenantId,
        public string $ticketId,
        public ?string $actorId,
        public array $changes,
    ) {}
}
