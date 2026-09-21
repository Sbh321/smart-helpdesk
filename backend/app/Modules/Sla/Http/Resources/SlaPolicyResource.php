<?php

declare(strict_types=1);

namespace App\Modules\Sla\Http\Resources;

use App\Modules\Sla\Models\SlaPolicy;
use App\Modules\Sla\Models\SlaTarget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An SLA policy: which tickets it matches, its calendar and the first-response and resolution targets per priority.
 *
 * @mixin SlaPolicy
 */
final class SlaPolicyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_default' => $this->is_default,
            'applies_to_tier' => $this->applies_to_tier,
            'warning_fraction' => (float) $this->warning_fraction,
            'calendar_id' => $this->calendar_id,
            'version' => $this->version,
            'targets' => SlaTargetResource::collection($this->targets->sortBy(fn (SlaTarget $target): string => $target->priority_level->value)->values()),
        ];
    }
}
