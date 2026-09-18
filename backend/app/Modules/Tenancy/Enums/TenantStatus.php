<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Enums;

enum TenantStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Archived = 'archived';
}
