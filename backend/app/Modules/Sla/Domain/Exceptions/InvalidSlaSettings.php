<?php

declare(strict_types=1);

namespace App\Modules\Sla\Domain\Exceptions;

use InvalidArgumentException;

/**
 * An SLA target or strategy setting breaks the rules of docs/05-algorithms/sla-evaluation.md.
 */
final class InvalidSlaSettings extends InvalidArgumentException {}
