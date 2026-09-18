<?php

declare(strict_types=1);

namespace App\Modules\Sla\Domain\Timer;

enum SlaEventType: string
{
    case Started = 'started';
    case Paused = 'paused';
    case Resumed = 'resumed';
    case Recomputed = 'recomputed';
    case Warning = 'warning';
    case Breached = 'breached';
    case Met = 'met';
    case Cancelled = 'cancelled';
}
