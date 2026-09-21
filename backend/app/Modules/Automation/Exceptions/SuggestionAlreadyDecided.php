<?php

declare(strict_types=1);

namespace App\Modules\Automation\Exceptions;

use App\Support\Errors\DomainException;
use App\Support\Errors\ErrorCode;

/**
 * An accepted duplicate suggestion closed a ticket; it cannot be dismissed afterwards.
 */
final class SuggestionAlreadyDecided extends DomainException
{
    public static function accepted(): self
    {
        return new self('This suggestion was accepted and the ticket was closed as a duplicate.', ['decision' => 'accepted']);
    }

    public function code(): ErrorCode
    {
        return ErrorCode::AlreadyDecided;
    }
}
