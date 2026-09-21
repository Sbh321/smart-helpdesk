<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Events;

/**
 * Synchronous creation hook: SLA timer rows must commit or roll back with the ticket.
 */
final readonly class TicketCreated
{
    public function __construct(
        public string $tenantId,
        public string $ticketId,
    ) {}
}
