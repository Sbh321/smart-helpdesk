<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Resources;

use App\Modules\Reporting\Reports\ReportDescription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a report accepts: its dimensions, measures (with units), filters, default group and chart.
 *
 * @mixin ReportDescription
 */
final class ReportDefinitionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'description' => $this->description,
            'group' => $this->group,
            'chart' => $this->chart,
            /** The measures the chart draws by default; for `stacked_bar`, the parts of a whole. */
            'chart_measures' => $this->chartMeasures,
            /** False for a report of the present: the period and comparison do not apply. */
            'period_applies' => $this->periodApplies,
            'default_dimension' => $this->defaultDimension,
            'drill_down_to' => $this->drillDownTo,
            'periods' => $this->periods,
            'dimensions' => $this->dimensions,
            'measures' => $this->measures,
            'filters' => $this->filters,
        ];
    }
}
