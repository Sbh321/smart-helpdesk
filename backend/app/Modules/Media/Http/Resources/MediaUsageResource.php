<?php

declare(strict_types=1);

namespace App\Modules\Media\Http\Resources;

use App\Modules\Media\Domain\MediaUsage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Storage used by the workspace against its quota.
 *
 * @mixin MediaUsage
 */
final class MediaUsageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'used_bytes' => $this->used_bytes,
            'quota_bytes' => $this->quota_bytes,
            'pending_bytes' => $this->pending_bytes,
        ];
    }
}
