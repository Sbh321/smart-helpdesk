<?php

declare(strict_types=1);

namespace App\Support\Auth;

/**
 * An authenticated API client (docs/07-api/authentication.md §3). Modules that record who did
 * something check for this contract instead of importing the Integrations model, which keeps the
 * dependency arrows of docs/03-architecture/backend.md intact.
 */
interface ApiClientPrincipal
{
    /** Actor type written to audit_logs and entity_changes. */
    public const ACTOR_TYPE = 'api_client';

    /** Actor type in ticket_events, whose column predates M3-04 and is eight characters wide. */
    public const TICKET_ACTOR_TYPE = 'client';

    public function clientId(): string;
}
