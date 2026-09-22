<?php

declare(strict_types=1);

namespace App\Modules\Mail\Http\Requests;

use App\Modules\Mail\Enums\InboundState;
use App\Support\Http\Requests\ListRequest;
use Illuminate\Validation\Validator;

/**
 * `GET /v1/inbound-emails` (docs/07-api/pagination-filtering.md §Filtering): a cursor feed, newest
 * first, so `sort`, `page` and `search` are not accepted; `cursor` and `per_page` page it.
 */
final class IndexInboundEmailsRequest extends ListRequest
{
    public function rules(): array
    {
        $rules = parent::rules();

        return [
            'per_page' => $rules['per_page'],
            'filter' => $rules['filter'],
            'cursor' => ['sometimes', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);

        $validator->after(function (Validator $validator): void {
            foreach (['page', 'search'] as $unsupported) {
                if ($this->has($unsupported)) {
                    $validator->errors()->add($unsupported, "The inbound log is a cursor feed without [{$unsupported}].");
                }
            }
        });
    }

    protected function sortable(): array
    {
        return [];
    }

    protected function defaultSort(): string
    {
        return '-created_at';
    }

    protected function filterRules(): array
    {
        return [
            'state' => ['in:'.implode(',', array_map(fn (InboundState $state): string => $state->value, InboundState::cases()))],
            'ticket_id' => ['uuid'],
        ];
    }
}
