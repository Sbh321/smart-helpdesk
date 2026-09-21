<?php

declare(strict_types=1);

namespace App\Modules\Automation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PreviewPriorityRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'weights' => ['required', 'array:impact,urgency,tier,age', 'size:4'],
            'weights.impact' => ['required', 'numeric', 'between:0,1'],
            'weights.urgency' => ['required', 'numeric', 'between:0,1'],
            'weights.tier' => ['required', 'numeric', 'between:0,1'],
            'weights.age' => ['required', 'numeric', 'between:0,1'],
            'thresholds' => ['required', 'array:P1,P2,P3', 'size:3'],
            'thresholds.P1' => ['required', 'numeric', 'between:0,100'],
            'thresholds.P2' => ['required', 'numeric', 'between:0,100'],
            'thresholds.P3' => ['required', 'numeric', 'between:0,100'],
            'age_full_hours' => ['required', 'numeric', 'gt:0'],
            'samples' => ['required', 'array', 'min:1', 'max:20'],
            'samples.*.impact' => ['required', 'integer', 'between:1,4'],
            'samples.*.urgency' => ['required', 'integer', 'between:1,4'],
            'samples.*.tier' => ['required', Rule::in(['standard', 'premium', 'enterprise'])],
            'samples.*.hours_waited' => ['required', 'numeric', 'min:0'],
        ];
    }
}
