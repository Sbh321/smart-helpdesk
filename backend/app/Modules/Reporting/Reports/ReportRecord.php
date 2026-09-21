<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports;

/** One record behind a number (drill-down), in one shape for every entity. */
final readonly class ReportRecord
{
    public function __construct(
        public string $id,
        public string $entity,
        public string $label,
        public ?string $subtitle = null,
        public ?string $status = null,
    ) {}
}
