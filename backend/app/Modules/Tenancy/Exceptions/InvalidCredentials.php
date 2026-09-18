<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

use App\Support\Errors\DomainException;
use App\Support\Errors\ErrorCode;

/**
 * One message for unknown workspace, unknown email and wrong password, so the response never
 * reveals which workspaces exist (docs/03-architecture/security.md).
 */
final class InvalidCredentials extends DomainException
{
    public static function make(): self
    {
        return new self('The workspace, email or password is incorrect.');
    }

    public function code(): ErrorCode
    {
        return ErrorCode::InvalidCredentials;
    }
}
