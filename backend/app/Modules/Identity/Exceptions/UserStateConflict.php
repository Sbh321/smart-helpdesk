<?php

declare(strict_types=1);

namespace App\Modules\Identity\Exceptions;

use App\Support\Errors\DomainException;
use App\Support\Errors\ErrorCode;

/**
 * 409 `conflict` with `meta.reason`: the user is not in a state that allows the action
 * (`self`, `already_accepted`, `disabled`).
 */
final class UserStateConflict extends DomainException
{
    public static function because(string $reason, string $detail): self
    {
        return new self($detail, ['reason' => $reason]);
    }

    public function code(): ErrorCode
    {
        return ErrorCode::Conflict;
    }
}
