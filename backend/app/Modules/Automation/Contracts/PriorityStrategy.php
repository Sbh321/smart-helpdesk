<?php

declare(strict_types=1);

namespace App\Modules\Automation\Contracts;

use App\Modules\Automation\Domain\Priority\PriorityInput;
use App\Modules\Automation\Domain\Priority\PriorityResult;

/**
 * Computes a ticket's priority score and level (docs/05-algorithms/priority-scoring.md, ADR-0023).
 *
 * Implementations must be deterministic, keep the score within 0–100 and explain every part.
 */
interface PriorityStrategy
{
    public function score(PriorityInput $input): PriorityResult;
}
