<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

use App\Support\Errors\DomainException;
use App\Support\Errors\ErrorCode;

final class TenantSuspended extends DomainException
{
    public static function make(): self
    {
        return new self('This workspace is suspended. Contact the platform administrator.');
    }

    public function code(): ErrorCode
    {
        return ErrorCode::TenantSuspended;
    }
}
