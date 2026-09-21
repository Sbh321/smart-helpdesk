<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Http\Resources;

use App\Modules\Contacts\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A tag on tickets, contacts and organisations.
 *
 * @mixin Tag
 */
final class TagResource extends JsonResource
{
    /**
     * @return array{id: string, name: string, slug: string, color: ?string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'color' => $this->color,
        ];
    }
}
