<?php

declare(strict_types=1);

namespace App\Modules\Automation\Http\Resources;

use App\Modules\Automation\Domain\Priority\PriorityResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PriorityResult */
final class PriorityPreviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'strategy' => $this->strategy,
            'strategy_version' => $this->strategyVersion,
            'score' => $this->score,
            'level' => $this->level,
            'parts' => array_map(static fn ($part): array => $part->toArray(), $this->parts),
            'settings' => $this->settings,
        ];
    }
}
