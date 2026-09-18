<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Enums;

/**
 * Customer tier of an organisation. Feeds the priority customer-tier factor and SLA policy
 * selection; contacts without an organisation count as `standard` (docs/04-domain/contacts.md).
 */
enum OrganizationTier: string
{
    case Standard = 'standard';
    case Premium = 'premium';
    case Enterprise = 'enterprise';
}
