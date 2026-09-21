<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Events;

/**
 * Synchronous hook, fired inside the comment transaction when an agent's first public reply is
 * stored. Sla listens and meets the first-response timer, so the timer change commits or rolls
 * back with the comment.
 */
final readonly class FirstPublicReplyRecorded
{
    public function __construct(
        public string $tenantId,
        public string $ticketId,
    ) {}
}
