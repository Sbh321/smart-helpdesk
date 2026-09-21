<?php

declare(strict_types=1);

namespace App\Modules\Automation\Domain\Exceptions;

use App\Support\Errors\DomainException;
use App\Support\Errors\ErrorCode;

/**
 * Rendered as 422 `no_eligible_agent` by `POST /tickets/{ticket}/auto-assign`; `meta.exclusions`
 * lists the failed rule per agent (docs/07-api/errors.md). The attempt itself is already stored
 * in `ticket_assignments`, so this is thrown after the transaction, never inside it.
 */
final class NoEligibleAgentFound extends DomainException
{
    /**
     * @param  list<array<string, mixed>>  $exclusions
     */
    public static function for(string $ticketId, ?string $teamId, array $exclusions): self
    {
        return new self(
            'No agent is eligible for this ticket. Assign it manually or change the team.',
            ['ticket_id' => $ticketId, 'team_id' => $teamId, 'exclusions' => $exclusions],
        );
    }

    public function code(): ErrorCode
    {
        return ErrorCode::NoEligibleAgent;
    }
}
