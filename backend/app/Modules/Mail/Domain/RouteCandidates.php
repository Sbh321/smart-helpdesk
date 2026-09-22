<?php

declare(strict_types=1);

namespace App\Modules\Mail\Domain;

/**
 * What an inbound message's addresses and thread headers point at, in routing order
 * (docs/04-domain/email.md §Routing): ticket plus-addresses, then tickets named by `In-Reply-To` and
 * `References`, then workspace intake addresses. The resolver checks each against the database.
 */
final readonly class RouteCandidates
{
    /**
     * @param  list<string>  $plusAddressTickets  ticket ids from `ticket+<uuid>@`
     * @param  list<string>  $threadTickets  ticket ids from our Message-IDs, In-Reply-To first, then References newest first
     * @param  list<string>  $intakeWorkspaces  workspace slugs from `support+<slug>@`
     */
    public function __construct(
        public array $plusAddressTickets,
        public array $threadTickets,
        public array $intakeWorkspaces,
    ) {}

    public function isEmpty(): bool
    {
        return $this->plusAddressTickets === [] && $this->threadTickets === [] && $this->intakeWorkspaces === [];
    }
}
