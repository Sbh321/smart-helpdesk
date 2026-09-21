<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Resources;

use App\Modules\Reporting\Dashboard\DashboardView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * KPI tiles (value of the period and of the previous period, null when suppressed below five records)
 * and chart series. Each tile and series names the catalogue report and parameters it came from.
 *
 * @mixin DashboardView
 */
final class DashboardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'period' => $this->period,
            'from' => $this->from,
            'to' => $this->to,
            'timezone' => $this->timezone,
            'kpis' => $this->kpis,
            'series' => $this->series,
        ];
    }
}
