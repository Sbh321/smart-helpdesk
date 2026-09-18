<?php

declare(strict_types=1);

namespace App\Modules\Sla\Domain\Timer;

enum TimerKind: string
{
    case FirstResponse = 'first_response';
    case Resolution = 'resolution';
}
