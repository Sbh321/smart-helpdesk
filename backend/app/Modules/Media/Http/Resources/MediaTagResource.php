<?php

declare(strict_types=1);

namespace App\Modules\Media\Http\Resources;

use App\Modules\Contacts\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Tag */
final class MediaTagResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['name' => $this->name, 'slug' => $this->slug];
    }
}
