<?php

declare(strict_types=1);

namespace App\Modules\Automation\Http\Resources;

use App\Modules\Automation\Domain\Priority\PriorityResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A PriorityStrategy result: score, level and the explanation (terms of the weighted sum) with the strategy name and version.
 *
 * @mixin PriorityResult
 */
final class PriorityPreviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'strategy' => $this->strategy,
            'strategy_version' => $this->strategyVersion,
            'score' => $this->score,
            'level' => $this->level,
            /**
             * @var list<array{name: string, value: float, weight: float, contribution: float}>
             *
             * Terms of the weighted sum: scaled value (0–1), weight and contribution in score points.
             */
            'parts' => array_map(static fn ($part): array => $part->toArray(), $this->parts),
            /** The priority settings the preview was computed with. */
            'settings' => $this->settings,
        ];
    }
}
