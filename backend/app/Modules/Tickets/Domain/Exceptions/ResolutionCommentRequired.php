<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Domain\Exceptions;

use App\Support\Errors\DomainException;
use App\Support\Errors\ErrorCode;

/**
 * Resolving needs a resolution comment in the same request, unless the last comment is already an
 * agent's public reply (docs/04-domain/tickets.md §Transition rules). `meta.field` names the input
 * the client should highlight.
 */
final class ResolutionCommentRequired extends DomainException
{
    public static function make(): self
    {
        return new self('Add a resolution comment before resolving this ticket.', ['field' => 'comment']);
    }

    public function code(): ErrorCode
    {
        return ErrorCode::ResolutionCommentRequired;
    }
}
