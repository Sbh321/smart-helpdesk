<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Http\Resources;

use App\Modules\Contacts\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An Organisation: the company contacts belong to; its tier feeds priority and SLA policy selection.
 *
 * @mixin Organization
 */
final class OrganizationResource extends JsonResource
{
    // No @return shape: Scramble infers the exact keys and types from the array below.
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'domain' => $this->domain,
            'tier' => $this->tier,
            'external_ids' => $this->external_ids,
            'metadata' => $this->metadata,
            'contacts_count' => (int) $this->contacts_count,
            'tags' => TagResource::collection($this->tags),
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
