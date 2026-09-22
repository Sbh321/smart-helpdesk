<?php

declare(strict_types=1);

namespace App\Modules\Mail\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * An inbound message was handled (docs/04-domain/email.md §Inbound pipeline): it became a comment
 * or a ticket, or was ignored, left unrouted or rejected. Fired inside the workspace it was logged
 * in (`tenantId` null for platform rows), once per message; a duplicate delivery fires nothing.
 */
final readonly class InboundEmailProcessed implements ShouldDispatchAfterCommit
{
    public function __construct(
        public ?string $tenantId,
        public string $inboundEmailId,
        public string $state,
        public ?string $reason,
        public ?string $ticketId,
        public ?string $commentId,
    ) {}
}
