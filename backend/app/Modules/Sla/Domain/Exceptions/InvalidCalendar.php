<?php

declare(strict_types=1);

namespace App\Modules\Sla\Domain\Exceptions;

use InvalidArgumentException;

/**
 * A business calendar definition or calendar input is not usable.
 */
final class InvalidCalendar extends InvalidArgumentException {}
