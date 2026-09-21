<?php

declare(strict_types=1);

namespace App\Modules\Media\Http\Resources;

use App\Modules\Media\Domain\MediaTicketUse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A ticket a media item is attached to.
 *
 * @mixin MediaTicketUse
 */
final class MediaTicketUseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['number' => $this->number];
    }
}
