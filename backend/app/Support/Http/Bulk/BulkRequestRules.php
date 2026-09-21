<?php

declare(strict_types=1);

namespace App\Support\Http\Bulk;

/** `ticket_ids`: 1 to 100 distinct UUIDs (docs/07-api/conventions.md: bulk actions take at most 100 ids). */
trait BulkRequestRules
{
    /** @return array<string, list<string>> */
    protected function idRules(): array
    {
        return [
            'ticket_ids' => ['required', 'array', 'min:1', 'max:100'],
            'ticket_ids.*' => ['required', 'uuid', 'distinct'],
        ];
    }
}
