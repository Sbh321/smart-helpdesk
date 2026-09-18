<?php

declare(strict_types=1);

namespace App\Modules\Automation\Domain\Priority;

/**
 * Ticket priority level; P1 is the most urgent.
 */
enum PriorityLevel: string
{
    case P1 = 'P1';
    case P2 = 'P2';
    case P3 = 'P3';
    case P4 = 'P4';
}
