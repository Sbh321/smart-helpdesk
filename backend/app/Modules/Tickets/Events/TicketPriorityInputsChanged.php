<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Events;

/**
 * Synchronous hook, fired inside the ticket transaction when the inputs of the priority score are
 * set or changed (creation, impact or urgency edit). Automation listens and scores the ticket, so
 * Tickets never imports Automation (docs/03-architecture/backend.md §Dependency rules).
 *
 * `initial` is true at creation: the score is part of the `created` history entry, so no separate
 * `priority_changed` entry is written.
 */
final readonly class TicketPriorityInputsChanged
{
    public function __construct(
        public string $tenantId,
        public string $ticketId,
        public ?string $actorId,
        public bool $initial,
    ) {}
}
