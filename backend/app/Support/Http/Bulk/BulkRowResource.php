<?php

declare(strict_types=1);

namespace App\Support\Http\Bulk;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of a bulk answer: `ok`, and on failure the problem `code` and `detail` of that ticket.
 *
 * @mixin BulkRow
 */
final class BulkRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'ticket_id' => $this->id,
            'ok' => $this->ok,
            'code' => $this->code?->value,
            'detail' => $this->detail,
            /** @var array<string, mixed> */
            'details' => (object) $this->details,
        ];
    }
}
