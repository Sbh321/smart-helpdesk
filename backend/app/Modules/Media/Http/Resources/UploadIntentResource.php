<?php

declare(strict_types=1);

namespace App\Modules\Media\Http\Resources;

use App\Modules\Media\Domain\UploadIntent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin UploadIntent */
final class UploadIntentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'media_id' => $this->media_id,
            'url' => $this->url,
            'headers' => $this->headers,
        ];
    }
}
