<?php

declare(strict_types=1);

namespace App\Modules\Automation\Domain\Exceptions;

use App\Support\Errors\DomainException;
use App\Support\Errors\ErrorCode;

/**
 * Rendered as 409 `conflict`: unassign was asked for a ticket that has no agent (any more).
 */
final class TicketNotAssigned extends DomainException
{
    public static function for(string $ticketId, string $status): self
    {
        return new self('The ticket has no agent to unassign.', ['ticket_id' => $ticketId, 'status' => $status]);
    }

    public function code(): ErrorCode
    {
        return ErrorCode::Conflict;
    }
}
