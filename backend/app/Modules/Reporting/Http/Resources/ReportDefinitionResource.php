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
            'default_dimension' => $this->defaultDimension,
            'drill_down_to' => $this->drillDownTo,
            'periods' => $this->periods,
            'dimensions' => $this->dimensions,
            'measures' => $this->measures,
            'filters' => $this->filters,
        ];
    }
}
