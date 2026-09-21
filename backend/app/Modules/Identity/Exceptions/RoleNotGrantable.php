<?php

declare(strict_types=1);

namespace App\Modules\Identity\Exceptions;

use App\Support\Errors\DomainException;
use App\Support\Errors\ErrorCode;

/**
 * 403 `forbidden` with `meta.roles`: the actor may not give or take these roles (or, from a role edit,
 * these permissions)
 * (docs/03-architecture/security.md §Role assignment).
 */
final class RoleNotGrantable extends DomainException
{
    /** @param list<string> $roles */
    public static function because(string $detail, array $roles): self
    {
        return new self($detail, ['roles' => $roles]);
    }

    public function code(): ErrorCode
    {
        return ErrorCode::Forbidden;
    }
}
