<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Resources;

use App\Modules\Reporting\Overviews\EntityOverview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Key metrics, related records and trends of one record. The keys of `metrics`, `related` and `trends`
 * depend on the entity (docs/04-domain/reporting.md §Entity 360).
 *
 * @mixin EntityOverview
 */
final class EntityOverviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'entity' => $this->entity,
            'id' => $this->id,
            'title' => $this->title,
            'metrics' => (object) $this->metrics,
            'related' => (object) $this->related,
            'trends' => (object) $this->trends,
        ];
    }
}
