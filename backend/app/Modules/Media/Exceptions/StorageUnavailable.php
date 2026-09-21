<?php

declare(strict_types=1);

namespace App\Modules\Media\Exceptions;

use App\Support\Errors\DomainException;
use App\Support\Errors\ErrorCode;
use Throwable;

/** 502 `storage_unavailable`: object storage did not answer or refused the operation. */
final class StorageUnavailable extends DomainException
{
    public static function during(string $operation, ?Throwable $previous = null): self
    {
        return new self('File storage is unavailable; try again shortly.', ['operation' => $operation], $previous);
    }

    public function code(): ErrorCode
    {
        return ErrorCode::StorageUnavailable;
    }
}
