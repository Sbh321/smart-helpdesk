<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Resources;

use App\Modules\Reporting\Models\EntityChange;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One recorded change: version, operation, the changed attributes with old and new values, who and when.
 *
 * @mixin EntityChange
 */
final class EntityChangeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            /** @var 'insert'|'update'|'delete' */
            'operation' => $this->operation,
            /** @var array<string, array{old: mixed, new: mixed}> */
            'changes' => (object) $this->changedAttributes(),
            'actor_type' => $this->actor_type,
            'actor_id' => $this->actor_id,
            'occurred_at' => $this->occurred_at->toIso8601ZuluString('microsecond'),
        ];
    }
}
