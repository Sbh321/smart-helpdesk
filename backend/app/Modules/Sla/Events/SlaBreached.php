<?php

declare(strict_types=1);

namespace App\Modules\Sla\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class SlaBreached implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $tenantId,
        public string $ticketId,
        public string $timerId,
    ) {}
}
