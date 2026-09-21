<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Resources;

use App\Modules\Reporting\Domain\History\AsOfView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `exists` is false before the record was created or after it was deleted; `differences` lists the
 * recorded attributes whose value then differs from now.
 *
 * @mixin AsOfView
 */
final class AsOfResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'at' => $this->at,
            'exists' => $this->attributes !== null,
            'attributes' => $this->attributes === null ? null : (object) $this->attributes,
            'differences' => (object) $this->differences,
            'versions_after' => $this->versionsAfter,
        ];
    }
}
