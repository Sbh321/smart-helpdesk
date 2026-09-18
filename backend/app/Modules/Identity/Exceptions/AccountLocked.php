<?php

declare(strict_types=1);

namespace App\Modules\Identity\Exceptions;

use App\Support\Errors\DomainException;
use App\Support\Errors\ErrorCode;

final class AccountLocked extends DomainException
{
    public static function make(int $minutes): self
    {
        return new self(
            "Too many failed sign-in attempts. Try again in {$minutes} minutes or reset your password.",
            ['retry_after_minutes' => $minutes],
        );
    }

    public function code(): ErrorCode
    {
        return ErrorCode::AccountLocked;
    }
}
