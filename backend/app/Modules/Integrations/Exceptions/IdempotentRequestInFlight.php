<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Exceptions;

use App\Support\Errors\DomainException;
use App\Support\Errors\ErrorCode;

final class IdempotentRequestInFlight extends DomainException
{
    public static function make(): self
    {
        return new self('A request with this Idempotency-Key is still being processed. Retry shortly.');
    }

    public function code(): ErrorCode
    {
        return ErrorCode::Conflict;
    }
}
