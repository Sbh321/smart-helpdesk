<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Domain\Exceptions;

use App\Support\Errors\DomainException;
use App\Support\Errors\ErrorCode;

final class InvalidDuplicateTarget extends DomainException
{
    public function __construct()
    {
        parent::__construct('The selected Ticket cannot be the original of this duplicate.');
    }

    public function code(): ErrorCode
    {
        return ErrorCode::DuplicateTargetInvalid;
    }
}
