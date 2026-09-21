<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Requests;

use App\Modules\Tickets\Http\Requests\IndexTicketsRequest;

/**
 * `POST /v1/exports/tickets`: the ticket-list query of `GET /v1/tickets` (`filter[…]`, `search`,
 * `sort`) in the body, validated by the same rules, plus the format. Paging is ignored.
 */
final class ExportTicketsRequest extends IndexTicketsRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            /**
             * Filter name => comma list, as `filter[…]` of `GET /v1/tickets`.
             *
             * @var array<string, string>
             */
            'filter' => ['sometimes', 'array'],
            // csv or xlsx.
            'format' => ['required', 'string', 'in:csv,xlsx'],
        ];
    }
}
