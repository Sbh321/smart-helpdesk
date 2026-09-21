<?php

declare(strict_types=1);

namespace App\Modules\Automation\Domain\Exceptions;

use App\Support\Errors\DomainException;
use App\Support\Errors\ErrorCode;

/**
 * Rendered as 409 `already_assigned` (docs/07-api/errors.md): the requested assignment is the
 * current one, usually because another request won. `meta` carries the current assignment.
 */
final class TicketAlreadyAssigned extends DomainException
{
    public static function to(string $ticketId, ?string $agentId, ?string $teamId): self
    {
        return new self(
            'The ticket already has this assignment.',
            ['ticket_id' => $ticketId, 'agent_id' => $agentId, 'team_id' => $teamId],
        );
    }

    public function code(): ErrorCode
    {
        return ErrorCode::AlreadyAssigned;
    }
}
