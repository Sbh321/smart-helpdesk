<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Events;

/**
 * Synchronous transactional hook for modules that persist ticket-dependent rows.
 */
final readonly class TicketLifecycleChanged
{
    public function __construct(
        public string $tenantId,
        public string $ticketId,
        public string $from,
        public string $to,
    ) {}
}
