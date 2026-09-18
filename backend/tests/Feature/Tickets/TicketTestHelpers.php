<?php

declare(strict_types=1);

use App\Modules\Contacts\Models\Contact;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tickets\Models\Category;

/**
 * A contact and a category in the tenant, the minimum a ticket needs.
 *
 * @return array{Contact, Category}
 */
function ticketPrerequisites(Tenant $tenant): array
{
    return [
        Contact::factory()->forTenant($tenant)->create(),
        Category::factory()->forTenant($tenant)->create(),
    ];
}
