<?php

declare(strict_types=1);

namespace App\Modules\Automation\Domain\Assignment;

/**
 * What a ticket requires from its agent.
 */
final readonly class TicketNeeds
{
    /**
     * @param  list<string>  $requiredSkills  skills required by the ticket's category (empty: any agent)
     * @param  string|null  $teamId  the ticket's team, when it already has one
     * @param  bool  $enforceShifts  tenant setting `shifts.enforce`
     */
    public function __construct(
        public string $ticketId,
        public array $requiredSkills = [],
        public ?string $teamId = null,
        public bool $enforceShifts = false,
    ) {}
}
