<?php

declare(strict_types=1);

namespace App\Modules\Billing\Enums;

enum PlanKind: string
{
    case Trial = 'trial';
    case Paid = 'paid';
}
