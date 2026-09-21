<?php

declare(strict_types=1);

namespace App\Modules\Agents\Http\Resources;

use App\Modules\Agents\Models\AgentShift;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AgentShift */
final class AgentShiftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'weekday' => $this->weekday,
            'date' => $this->date?->format('Y-m-d'),
            'starts_at' => substr($this->starts_at, 0, 5),
            'ends_at' => substr($this->ends_at, 0, 5),
            'is_off' => $this->is_off,
        ];
    }
}
