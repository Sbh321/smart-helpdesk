<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Http\Requests;

use App\Support\Http\Requests\ListRequest;
use Illuminate\Validation\Validator;

/**
 * `GET /v1/tickets` (docs/07-api/pagination-filtering.md §Ticket list filters).
 */
final class IndexTicketsRequest extends ListRequest
{
    public const INCLUDES = ['contact', 'organization', 'category', 'tags'];

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [...parent::rules(), 'include' => ['sometimes', 'string', 'max:100']];
    }

    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);

        $validator->after(function (Validator $validator): void {
            $unknown = array_diff($this->includes(), self::INCLUDES);

            if ($unknown !== []) {
                $validator->errors()->add('include', 'Unknown include: '.implode(', ', $unknown).'.');
            }

            $range = $this->filterValues('created_between');

            if ($range !== [] && (count($range) !== 2 || $range[0] > $range[1])) {
                $validator->errors()->add('filter.created_between', 'Use two dates, start first: YYYY-MM-DD,YYYY-MM-DD.');
            }
        });
    }

    /**
     * @return list<string>
     */
    public function includes(): array
    {
        $include = $this->input('include');

        return is_string($include) ? array_values(array_filter(array_map('trim', explode(',', $include)))) : [];
    }

    protected function sortable(): array
    {
        return ['priority_score', 'priority_level', 'created_at', 'updated_at', 'number', 'status', 'sla_due_at'];
    }

    protected function defaultSort(): string
    {
        return '-priority_score,-created_at';
    }

    protected function filterRules(): array
    {
        $uuid = ['regex:'.self::UUID];

        return [
            'status' => ['in:open,assigned,in_progress,pending,resolved,closed,active'],
            'priority' => ['in:P1,P2,P3,P4'],
            'assignee_id' => ['regex:/^(unassigned|me|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/i'],
            'team_id' => ['regex:/^(none|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/i'],
            'category_id' => $uuid,
            'organization_id' => $uuid,
            'contact_id' => $uuid,
            'tag' => ['string', 'max:40'],
            'created_between' => ['date_format:Y-m-d'],
            'updated_since' => ['date'],
            'impact' => ['integer', 'between:1,4'],
            'urgency' => ['integer', 'between:1,4'],
            'number' => ['integer', 'min:1'],
            // The resolution timer of the latest cycle.
            'sla_state' => ['in:running,warning,breached,paused,met'],
            'has_duplicate_suggestion' => ['in:true'],
        ];
    }
}
