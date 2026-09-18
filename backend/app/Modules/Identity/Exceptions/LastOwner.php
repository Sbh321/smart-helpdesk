<?php

declare(strict_types=1);

namespace App\Modules\Identity\Exceptions;

use App\Support\Errors\DomainException;
use App\Support\Errors\ErrorCode;

final class LastOwner extends DomainException
{
    public static function make(): self
    {
        return new self('This workspace must keep at least one active owner.');
    }

    public function code(): ErrorCode
    {
        return ErrorCode::LastOwner;
    }
}
