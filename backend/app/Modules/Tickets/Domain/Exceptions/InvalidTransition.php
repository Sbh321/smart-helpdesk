<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Domain\Exceptions;

use App\Modules\Tickets\Domain\TicketStatus;
use App\Support\Errors\DomainException;
use App\Support\Errors\ErrorCode;

/**
 * Rendered as 422 `invalid_transition` with the allowed targets in `meta.allowed`
 * (docs/07-api/errors.md).
 */
final class InvalidTransition extends DomainException
{
    public static function between(TicketStatus $from, TicketStatus $to): self
    {
        return new self(
            sprintf('A ticket cannot move from %s to %s.', $from->value, $to->value),
            [
                'from' => $from->value,
                'to' => $to->value,
                'allowed' => array_map(fn (TicketStatus $status): string => $status->value, $from->allowedTargets()),
            ],
        );
    }

    public function code(): ErrorCode
    {
        return ErrorCode::InvalidTransition;
    }
}
