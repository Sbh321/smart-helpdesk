<?php

declare(strict_types=1);

namespace App\Modules\Automation\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Nobody could take the ticket; it stays open with its routed team. The manager notification
 * (M2-09) listens to this.
 */
final readonly class NoEligibleAgent implements ShouldDispatchAfterCommit
{
    /**
     * @param  list<array<string, mixed>>  $exclusions  agent id, reason and missing skills per agent
     */
    public function __construct(
        public string $tenantId,
        public string $ticketId,
        public ?string $teamId = null,
        public array $exclusions = [],
    ) {}
}
