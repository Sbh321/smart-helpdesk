<?php

declare(strict_types=1);

namespace App\Modules\Agents\Domain\Exceptions;

use App\Support\Errors\DomainException;
use App\Support\Errors\ErrorCode;

/**
 * Rendered as 409 `in_use`; `meta.references` counts what still points at the record
 * (docs/07-api/errors.md).
 */
final class RecordInUse extends DomainException
{
    /**
     * @param  array<string, int>  $references  only the non-zero counts are reported
     */
    public static function for(string $resource, string $id, array $references): self
    {
        return new self(
            sprintf('This %s is still in use and cannot be deleted.', $resource),
            ['resource' => $resource, 'id' => $id, 'references' => array_filter($references)],
        );
    }

    public function code(): ErrorCode
    {
        return ErrorCode::InUse;
    }
}
