<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Exports;

/**
 * What an export writes: one header row and the data rows, produced lazily so a large ticket list
 * never sits in memory. `title` names the sheet and the file; `summaryRows` trailing rows (a report's
 * `Total`) are written but not counted in `report_exports.row_count`.
 */
final readonly class ExportTable
{
    /**
     * @param  list<string>  $headers
     * @param  iterable<list<string|int|float|null>>  $rows
     */
    public function __construct(
        public string $title,
        public array $headers,
        public iterable $rows,
        public int $summaryRows = 0,
    ) {}
}
