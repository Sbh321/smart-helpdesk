<?php

declare(strict_types=1);

namespace App\Modules\Automation\Contracts;

use App\Modules\Automation\Domain\Duplicates\DuplicateResult;
use App\Modules\Automation\Domain\Duplicates\TicketText;

/**
 * Suggests existing tickets that may describe the same problem (docs/05-algorithms/duplicate-detection.md, ADR-0023).
 *
 * Implementations must be deterministic and independent of the order of the candidates.
 */
interface DuplicateStrategy
{
    /**
     * @param  list<TicketText>  $candidates  recent tickets of the same tenant, loaded by the caller
     */
    public function find(TicketText $ticket, array $candidates): DuplicateResult;
}
