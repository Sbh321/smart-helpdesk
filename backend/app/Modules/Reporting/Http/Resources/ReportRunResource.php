<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Resources;

use App\Modules\Reporting\Reports\ReportRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Rows per value of the chosen dimension (`values` keyed by measure), totals over the period, the
 * previous period's totals (null when not asked for or fewer than five records) and whether rows were cut.
 *
 * @mixin ReportRun
 */
final class ReportRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'report' => $this->report,
            'parameters' => $this->parameters,
            'rows' => $this->rows,
            'totals' => $this->totals,
            'previous' => $this->previous,
            'truncated' => $this->truncated,
        ];
    }
}
