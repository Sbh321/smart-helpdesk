<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A contact was created or updated (after commit). Integrations maps it to the `contact.created`
 * and `contact.updated` webhook events (docs/07-api/webhooks.md §Event catalogue).
 */
final readonly class ContactSaved implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $tenantId,
        public string $contactId,
        public bool $created,
    ) {}
}
