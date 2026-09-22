<?php

declare(strict_types=1);

namespace App\Modules\Audit\Http\Requests;

use App\Modules\Audit\Enums\ActorType;
use App\Support\Http\Requests\ListRequest;
use Illuminate\Validation\Validator;

/**
 * `GET /v1/audit-logs` (docs/07-api/pagination-filtering.md §Filtering): a cursor feed in the fixed
 * order newest first, so `sort` and `page` are not accepted; `cursor` and `per_page` page it.
 */
final class IndexAuditLogsRequest extends ListRequest
{
    private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

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
                    $validator->errors()->add($unsupported, "The audit log is a cursor feed without [{$unsupported}].");
                }
            }
            $range = $this->filterValues('created_between');
            if ($range !== [] && (count($range) !== 2 || $range[0] > $range[1])) {
                $validator->errors()->add('filter.created_between', 'Use two dates, start first: YYYY-MM-DD,YYYY-MM-DD.');
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
            // An exact action (`user.invited`) or every action of a subject (`user.*`).
            'action' => ['regex:/^[a-z_]+(\.([a-z_]+|\*))?$/', 'max:48'],
            'actor_type' => ['in:'.implode(',', array_map(fn (ActorType $type): string => $type->value, ActorType::cases()))],
            'actor_id' => ['regex:/^'.self::UUID.'$/'],
            'subject_type' => ['regex:/^[a-z_]+$/', 'max:64'],
            'subject_id' => ['regex:/^'.self::UUID.'$/'],
            'created_between' => ['date_format:Y-m-d'],
        ];
    }
}
