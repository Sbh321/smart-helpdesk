<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Domain;

/**
 * Ticket priority level. The score behind it comes from the PriorityStrategy (M2-04); a manual
 * override wins over the computed level (docs/04-domain/tickets.md §Fields).
 */
enum Priority: string
{
    case P1 = 'P1';
    case P2 = 'P2';
    case P3 = 'P3';
    case P4 = 'P4';

    /**
     * 1 is the most urgent.
     */
    public function rank(): int
    {
        return (int) substr($this->value, 1);
    }
}
