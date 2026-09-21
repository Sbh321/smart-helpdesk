<?php

declare(strict_types=1);

namespace App\Modules\Automation\Domain\Exceptions;

use App\Support\Errors\DomainException;
use App\Support\Errors\ErrorCode;

/**
 * Rendered as 409 `conflict`: the priority of a resolved or closed ticket is history and is not
 * overridden any more; reopen the ticket first.
 */
final class PriorityOverrideNotAllowed extends DomainException
{
    public static function forStatus(string $ticketId, string $status): self
    {
        return new self(
            'The priority of a resolved or closed ticket cannot be overridden.',
            ['ticket_id' => $ticketId, 'status' => $status],
        );
    }

    public function code(): ErrorCode
    {
        return ErrorCode::Conflict;
    }
}
