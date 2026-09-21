<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Resources;

use App\Modules\Reporting\Reports\ReportRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One record behind a report number (drill-down).
 *
 * @mixin ReportRecord
 */
final class ReportRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entity' => $this->entity,
            'label' => $this->label,
            'subtitle' => $this->subtitle,
            'status' => $this->status,
        ];
    }
}
