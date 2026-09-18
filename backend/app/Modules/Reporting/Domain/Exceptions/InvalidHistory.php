<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Exceptions;

use InvalidArgumentException;

/**
 * A change history or event list cannot be replayed or swept (duplicate versions, time running backwards,
 * an update before the insert, events before the start).
 */
final class InvalidHistory extends InvalidArgumentException {}
