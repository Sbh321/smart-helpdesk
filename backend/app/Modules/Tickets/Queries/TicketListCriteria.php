<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Queries;

/**
 * What the ticket list is asked for, independent of HTTP: `GET /v1/tickets` builds it from its
 * request, a ticket-list export (docs/04-domain/reporting.md §Exports) from the stored parameters.
 * Values are already validated by `IndexTicketsRequest`'s rules.
 */
final readonly class TicketListCriteria
{
    /**
     * @param  array<string, list<string>>  $filters  filter name => values (OR within, AND across)
     * @param  list<array{string, 'asc'|'desc'}>  $sort
     * @param  string|null  $userId  the signed-in user, for `filter[assignee_id]=me`
     * @param  list<string>  $includes
     */
    public function __construct(
        public array $filters = [],
        public ?string $search = null,
        public array $sort = [['priority_score', 'desc'], ['created_at', 'desc']],
        public bool $explicitSort = false,
        public ?string $userId = null,
        public array $includes = [],
    ) {}

    /** @return list<string> */
    public function filterValues(string $key): array
    {
        return $this->filters[$key] ?? [];
    }
}
