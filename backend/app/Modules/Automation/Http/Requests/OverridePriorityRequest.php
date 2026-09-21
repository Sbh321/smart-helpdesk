<?php

declare(strict_types=1);

namespace App\Modules\Automation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class OverridePriorityRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'level' => ['present', 'nullable', Rule::in(['P1', 'P2', 'P3', 'P4'])],
            'reason' => ['required_with:level', 'nullable', 'string', 'max:255'],
        ];
    }
}
