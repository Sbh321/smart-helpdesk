<?php

declare(strict_types=1);

namespace App\Modules\Mail\Support;

/** What `ProcessInboundEmail` did with one raw message: a logged state, or `duplicate` (already logged). */
final readonly class InboundOutcome
{
    public const string DUPLICATE = 'duplicate';

    public function __construct(
        public string $state,
        public ?string $inboundEmailId = null,
        public ?string $tenantId = null,
        public ?string $reason = null,
    ) {}
}
