<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Http\Resources;

use App\Modules\Contacts\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A contact with its organisation summary and tags. Both are always loaded by the list and item
 * endpoints, because every contact view shows them.
 *
 * @mixin Contact
 */
final class ContactResource extends JsonResource
{
    // No @return shape: Scramble infers the exact keys and types from the array below.
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'organization' => $this->organization === null ? null : [
                'id' => $this->organization->id,
                'name' => $this->organization->name,
                'tier' => $this->organization->tier,
            ],
            'tags' => TagResource::collection($this->tags),
            'external_ids' => $this->external_ids,
            'metadata' => $this->metadata,
            'last_ticket_at' => $this->last_ticket_at?->toIso8601ZuluString(),
            'archived_at' => $this->archived_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
