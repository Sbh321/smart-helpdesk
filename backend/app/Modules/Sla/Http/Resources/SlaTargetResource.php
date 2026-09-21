<?php

declare(strict_types=1);

namespace App\Modules\Sla\Http\Resources;

use App\Modules\Sla\Models\SlaTarget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SlaTarget */
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
