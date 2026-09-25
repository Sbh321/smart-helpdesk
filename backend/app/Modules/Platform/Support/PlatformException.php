<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use App\Support\Errors\DomainException;
use App\Support\Errors\ErrorCode;

/** A platform rule reported as problem details (ADR-0025 §7). */
final class PlatformException extends DomainException
{
    public function __construct(private readonly ErrorCode $errorCode, string $detail)
    {
        parent::__construct($detail);
    }

    public function code(): ErrorCode
    {
        return $this->errorCode;
    }
}
