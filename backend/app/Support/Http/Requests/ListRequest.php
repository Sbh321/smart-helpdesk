<?php

declare(strict_types=1);

namespace App\Support\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Shared contract for page-based collection endpoints (docs/07-api/pagination-filtering.md):
 * `page`, `per_page` (1–100, default 25), `sort` (comma list of allow-listed fields, `-` for
 * descending), `filter[field]` (comma list = OR, fields = AND) and `search` (≤ 200 characters).
 * Unknown sort fields and unknown filters answer 422 with the offending key.
 */
abstract class ListRequest extends FormRequest
{
    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    /**
     * @return list<string> fields that may appear in `sort`
     */
    abstract protected function sortable(): array;

    /**
     * Sort applied when the request names none, e.g. `name` or `-created_at`.
     */
    abstract protected function defaultSort(): string;

    /**
     * Validation rules per allowed filter, applied to each comma-separated value.
     *
     * @return array<string, list<mixed>>
     */
    abstract protected function filterRules(): array;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'sort' => ['sometimes', 'string', 'max:200'],
            'filter' => ['sometimes', 'array'],
            'search' => ['sometimes', 'nullable', 'string', 'max:200'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ($this->requestedSort() as [$field]) {
                if (! in_array($field, $this->sortable(), true)) {
                    $validator->errors()->add('sort', "Sorting by [{$field}] is not supported.");
                }
            }

            $filters = $this->input('filter', []);

            if (! is_array($filters)) {
                return;
            }

            $allowed = $this->filterRules();

            foreach ($filters as $key => $value) {
                if (! array_key_exists((string) $key, $allowed)) {
                    $validator->errors()->add("filter.{$key}", "Filtering by [{$key}] is not supported.");

                    continue;
                }

                if (! is_string($value) && ! is_numeric($value)) {
                    $validator->errors()->add("filter.{$key}", 'The filter must be a comma-separated list.');

                    continue;
                }

                foreach ($this->splitList((string) $value) as $item) {
                    $check = \Illuminate\Support\Facades\Validator::make(['value' => $item], ['value' => $allowed[(string) $key]]);

                    if ($check->fails()) {
                        $validator->errors()->add("filter.{$key}", "The value [{$item}] is not valid for this filter.");
                    }
                }
            }
        });
    }

    public function perPage(): int
    {
        return (int) $this->input('per_page', self::DEFAULT_PER_PAGE);
    }

    public function search(): ?string
    {
        $search = trim((string) $this->input('search', ''));

        return $search === '' ? null : $search;
    }

    public function hasExplicitSort(): bool
    {
        return $this->filled('sort');
    }

    /**
     * Sort as [field, direction] pairs; the default sort when none was requested.
     *
     * @return list<array{string, 'asc'|'desc'}>
     */
    public function sortColumns(): array
    {
        return $this->hasExplicitSort() ? $this->requestedSort() : $this->parseSort($this->defaultSort());
    }

    /**
     * Values of one filter, split on commas; empty when the filter is absent.
     *
     * @return list<string>
     */
    public function filterValues(string $key): array
    {
        $value = $this->input("filter.{$key}");

        return is_string($value) || is_numeric($value) ? $this->splitList((string) $value) : [];
    }

    /**
     * @return list<array{string, 'asc'|'desc'}>
     */
    private function requestedSort(): array
    {
        $sort = $this->input('sort');

        return is_string($sort) ? $this->parseSort($sort) : [];
    }

    /**
     * @return list<array{string, 'asc'|'desc'}>
     */
    private function parseSort(string $sort): array
    {
        $columns = [];

        foreach ($this->splitList($sort) as $part) {
            $columns[] = str_starts_with($part, '-') ? [substr($part, 1), 'desc'] : [$part, 'asc'];
        }

        return $columns;
    }

    /**
     * @return list<string>
     */
    private function splitList(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), fn (string $item): bool => $item !== ''));
    }
}
