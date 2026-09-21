<?php

declare(strict_types=1);

namespace App\Modules\Media\Exceptions;

use App\Support\Errors\DomainException;
use App\Support\Errors\ErrorCode;

/** 409 `in_use`: the record is still referenced (docs/07-api/errors.md). */
final class MediaInUse extends DomainException
{
    public static function item(int $links): self
    {
        return new self('This Media item is still linked; unlink it first.', ['links' => $links]);
    }

    public static function folder(): self
    {
        return new self('This folder still contains folders or Media items.');
    }

    public function code(): ErrorCode
    {
        return ErrorCode::InUse;
    }
}
