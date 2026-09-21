<?php

declare(strict_types=1);

namespace App\Modules\Media\Exceptions;

use App\Support\Errors\DomainException;
use App\Support\Errors\ErrorCode;

/** 409 `conflict` with `meta.reason`: the record is not in a state that allows the action. */
final class MediaStateConflict extends DomainException
{
    /** @param list<string> $expected */
    public static function state(string $actual, array $expected): self
    {
        return new self(
            sprintf('This Media item is %s; the action needs it to be %s.', $actual, implode(' or ', $expected)),
            ['reason' => 'media_state', 'state' => $actual, 'expected' => $expected],
        );
    }

    public static function completionInProgress(): self
    {
        return new self('This upload is already being completed.', ['reason' => 'completion_in_progress']);
    }

    public static function systemFolder(): self
    {
        return new self('System folders cannot be changed or deleted.', ['reason' => 'system_folder']);
    }

    public function code(): ErrorCode
    {
        return ErrorCode::Conflict;
    }
}
