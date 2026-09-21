<?php

declare(strict_types=1);

namespace App\Modules\Media\Exceptions;

use App\Support\Errors\DomainException;
use App\Support\Errors\ErrorCode;

final class QuotaExceeded extends DomainException
{
    public function __construct()
    {
        parent::__construct('This upload would exceed the workspace storage quota.');
    }

    public function code(): ErrorCode
    {
        return ErrorCode::QuotaExceeded;
    }
}
