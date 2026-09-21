<?php

declare(strict_types=1);

namespace App\Modules\Media\Exceptions;

use App\Support\Errors\DomainException;
use App\Support\Errors\ErrorCode;

/**
 * 422 `validation_failed` with `meta.reason`: the object in storage is not what the intent
 * declared. The item is marked failed and its reservation released before this is thrown.
 */
final class UploadRejected extends DomainException
{
    public const MISSING_OBJECT = 'missing_object';

    public const SIZE_MISMATCH = 'size_mismatch';

    public static function because(string $reason): self
    {
        $detail = match ($reason) {
            self::MISSING_OBJECT => 'The upload has not reached storage.',
            self::SIZE_MISMATCH => 'The uploaded file size does not match the intent.',
            'undecodable_image' => 'The uploaded image could not be read.',
            'invalid_document' => 'The uploaded document is not a valid Office file.',
            default => 'The uploaded file type does not match its name.',
        };

        return new self($detail, ['reason' => $reason]);
    }

    public function code(): ErrorCode
    {
        return ErrorCode::ValidationFailed;
    }
}
