<?php

declare(strict_types=1);

namespace App\Modules\Mail\Support;

use App\Modules\Tenancy\Models\Tenant;

/**
 * Where an inbound message goes (docs/04-domain/email.md §Routing): onto an existing ticket, into a
 * new ticket, or nowhere, with the reason. `$tenant` is the workspace the log row belongs to; it is
 * null only for messages that name no workspace.
 */
final readonly class InboundRoute
{
    public const string TICKET = 'ticket';

    public const string NEW_TICKET = 'new_ticket';

    public const string REJECTED = 'rejected';

    public const string UNROUTED = 'unrouted';

    private function __construct(
        public string $kind,
        public ?Tenant $tenant,
        public ?string $route,
        public ?string $ticketId,
        public ?string $contactId,
        public ?string $reason,
    ) {}

    /** A reply from `$contactId` to ticket `$ticketId`, found by plus-address or thread headers. */
    public static function ticket(Tenant $tenant, string $route, string $ticketId, string $contactId): self
    {
        return new self(self::TICKET, $tenant, $route, $ticketId, $contactId, null);
    }

    public static function newTicket(Tenant $tenant, string $route): self
    {
        return new self(self::NEW_TICKET, $tenant, $route, null, null, null);
    }

    public static function rejected(Tenant $tenant, string $route, ?string $ticketId, string $reason): self
    {
        return new self(self::REJECTED, $tenant, $route, $ticketId, null, $reason);
    }

    public static function unrouted(string $reason): self
    {
        return new self(self::UNROUTED, null, null, null, null, $reason);
    }
}
