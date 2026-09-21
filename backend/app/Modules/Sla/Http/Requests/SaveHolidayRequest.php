<?php

declare(strict_types=1);

namespace App\Modules\Sla\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveHolidayRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d', Rule::unique('calendar_holidays', 'date')
                ->where('tenant_id', tenant('id'))->where('calendar_id', $this->route('calendar')?->id)],
            'name' => ['required', 'string', 'max:120'],
            'recurs_yearly' => ['sometimes', 'boolean'],
        ];
    }
}
