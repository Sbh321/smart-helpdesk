<?php

declare(strict_types=1);

namespace App\Modules\Automation\Domain\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when strategy settings (weights, thresholds, limits) or inputs break the rules of the algorithm page.
 */
final class InvalidStrategySettings extends InvalidArgumentException {}
