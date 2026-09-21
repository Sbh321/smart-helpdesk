<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports;

/**
 * A narrowing a report accepts: `values` (a list matched with `IN`, `none` for null) or `boolean`.
 * `sql` is the column or expression the values are compared with.
 */
final readonly class Filter
{
    public function __construct(
        public string $label,
        public string $sql,
        public string $type = 'values',
        public ?string $labels = null,
    ) {}
}
