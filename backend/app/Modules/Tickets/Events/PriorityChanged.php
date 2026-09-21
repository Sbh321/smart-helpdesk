<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class PriorityChanged implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $tenantId,
        public string $ticketId,
        public string $from,
        public string $to,
    ) {}
}
