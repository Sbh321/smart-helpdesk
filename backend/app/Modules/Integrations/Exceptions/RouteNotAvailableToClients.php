<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Exceptions;

use App\Support\Errors\DomainException;
use App\Support\Errors\ErrorCode;

final class RouteNotAvailableToClients extends DomainException
{
    public static function make(): self
    {
        return new self('This endpoint is not available to API clients.');
    }

    public function code(): ErrorCode
    {
        return ErrorCode::Forbidden;
    }
}
