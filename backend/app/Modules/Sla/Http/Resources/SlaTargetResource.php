<?php

declare(strict_types=1);

namespace App\Modules\Sla\Http\Resources;

use App\Modules\Sla\Models\SlaTarget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * First-response and resolution targets of an SLA policy for one priority, in business-calendar minutes.
 *
 * @mixin SlaTarget
 */
final class SlaTargetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'priority_level' => $this->priority_level,
            'first_response_minutes' => $this->first_response_minutes,
            'resolution_minutes' => $this->resolution_minutes,
        ];
    }
}
