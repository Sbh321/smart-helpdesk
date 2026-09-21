<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Exceptions;

use App\Support\Errors\DomainException;
use App\Support\Errors\ErrorCode;

final class IdempotencyKeyReused extends DomainException
{
    public static function make(): self
    {
        return new self('This Idempotency-Key was already used with a different request body. Use a new key for a new request.');
    }

    public function code(): ErrorCode
    {
        return ErrorCode::IdempotencyKeyReused;
    }
}
