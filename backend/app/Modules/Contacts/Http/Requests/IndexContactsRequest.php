<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Http\Requests;

use App\Support\Http\Requests\ListRequest;

/**
 * `GET /v1/contacts` (docs/07-api/pagination-filtering.md): sort by name, email, created_at or
 * last_ticket_at; filter by organisation, tag and archive state; search name and email.
 */
final class IndexContactsRequest extends ListRequest
{
    protected function sortable(): array
    {
        return ['name', 'email', 'created_at', 'last_ticket_at'];
    }

    protected function defaultSort(): string
    {
        return 'name';
    }

    protected function filterRules(): array
    {
        return [
            // An organisation id, or `none` for contacts without one.
            'organization_id' => ['regex:/^(none|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/'],
            'tag' => ['string', 'max:40'],
            // `false` (default) hides archived contacts, `true` shows only them, `all` shows both.
            'archived' => ['in:true,false,all'],
        ];
    }
}
