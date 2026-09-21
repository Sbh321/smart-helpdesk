<?php

declare(strict_types=1);

namespace App\Modules\Identity\Exceptions;

use App\Support\Errors\DomainException;
use App\Support\Errors\ErrorCode;

/** 409 `in_use` with `meta.users`: a custom role still held by users cannot be deleted. */
final class RoleInUse extends DomainException
{
    public static function held(int $users): self
    {
        return new self('Users still hold this role. Give them another role first.', ['users' => $users]);
    }

    public function code(): ErrorCode
    {
        return ErrorCode::InUse;
    }
}
