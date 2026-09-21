<?php

declare(strict_types=1);

namespace App\Modules\Agents\Enums;

enum AgentAvailability: string
{
    case Available = 'available';
    case Away = 'away';
    case Offline = 'offline';
}
