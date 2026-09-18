<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\History;

/**
 * The `entity_changes.operation` values written by `record_entity_change()` (lower-case TG_OP).
 */
enum ChangeOperation: string
{
    case Insert = 'insert';
    case Update = 'update';
    case Delete = 'delete';
}
