<?php

declare(strict_types=1);

namespace App\Modules\Agents\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class ReplaceAgentShiftsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'shifts' => ['present', 'array', 'max:100'],
            'shifts.*.weekday' => ['nullable', 'integer', 'between:0,6', 'required_without:shifts.*.date'],
            'shifts.*.date' => ['nullable', 'date_format:Y-m-d', 'required_without:shifts.*.weekday'],
            'shifts.*.starts_at' => ['required', 'date_format:H:i'],
            'shifts.*.ends_at' => ['required', 'date_format:H:i'],
            'shifts.*.is_off' => ['required', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $buckets = [];
            foreach ($this->input('shifts', []) as $index => $shift) {
                if (! is_array($shift)) {
                    continue;
                }
                $weekday = $shift['weekday'] ?? null;
                $date = $shift['date'] ?? null;
                if (($weekday === null) === ($date === null)) {
                    $validator->errors()->add("shifts.{$index}.weekday", 'Choose exactly one weekday or date.');
                }
                $start = $shift['starts_at'] ?? '';
                $end = $shift['ends_at'] ?? '';
                if (is_string($start) && is_string($end) && $start >= $end) {
                    $validator->errors()->add("shifts.{$index}.ends_at", 'The end time must be after the start time.');
                }
                $bucket = $date !== null ? "date:{$date}" : "weekday:{$weekday}";
                foreach ($buckets[$bucket] ?? [] as [$otherStart, $otherEnd]) {
                    if ($start < $otherEnd && $end > $otherStart) {
                        $validator->errors()->add("shifts.{$index}.starts_at", 'This shift overlaps another shift.');
                    }
                }
                $buckets[$bucket][] = [$start, $end];
            }
        });
    }
}
