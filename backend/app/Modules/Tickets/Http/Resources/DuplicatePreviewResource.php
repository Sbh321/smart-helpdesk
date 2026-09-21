<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Http\Resources;

use App\Modules\Tickets\Domain\DuplicatePreview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DuplicatePreview */
final class DuplicatePreviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'strategy' => $this->strategy,
            'strategy_version' => $this->strategyVersion,
            'candidates_compared' => $this->candidatesCompared,
            'matches' => DuplicatePreviewMatchResource::collection($this->matches),
        ];
    }
}
