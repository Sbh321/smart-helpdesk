<?php

declare(strict_types=1);

namespace App\Support\Http\Bulk;

use App\Support\Errors\ErrorCode;

/** The outcome of one id in a bulk request. */
final readonly class BulkRow
{
    /** @param array<string, mixed> $details what the row shows: the new state on success, `meta` of the problem on failure */
    public function __construct(
        public string $id,
        public bool $ok,
        public ?ErrorCode $code = null,
        public ?string $detail = null,
        public array $details = [],
    ) {}
}
