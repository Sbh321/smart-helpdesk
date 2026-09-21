<?php

declare(strict_types=1);

namespace App\Modules\Sla\Exceptions;

use App\Support\Errors\DomainException;
use App\Support\Errors\ErrorCode;

/**
 * Rendered as 409 `in_use`; `meta.used_by` names what still depends on the record
 * (docs/07-api/errors.md).
 */
final class RecordInUse extends DomainException
{
    /**
     * @param  list<string>  $usedBy
     */
    public static function because(string $detail, string $record, array $usedBy): self
    {
        return new self($detail, ['record' => $record, 'used_by' => $usedBy]);
    }

    public function code(): ErrorCode
    {
        return ErrorCode::InUse;
    }
}
