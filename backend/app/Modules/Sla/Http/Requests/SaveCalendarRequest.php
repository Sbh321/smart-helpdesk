<?php

declare(strict_types=1);

namespace App\Modules\Sla\Http\Requests;

use App\Modules\Sla\Domain\Calendar\WorkingHoursCalendar;
use App\Modules\Sla\Models\BusinessCalendar;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Throwable;

final class SaveCalendarRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        $rules = [
            'name' => [$required, 'string', 'max:80', function (string $attribute, mixed $value, Closure $fail): void {
                $calendar = $this->route('calendar');
                if (is_string($value) && BusinessCalendar::query()->whereRaw('lower(name) = lower(?)', [$value])
                    ->when($calendar instanceof BusinessCalendar, fn ($query) => $query->whereKeyNot($calendar->id))->exists()) {
                    $fail('A business calendar with this name already exists.');
                }
            }],
            'timezone' => [$required, 'timezone'],
            'weekly_hours' => [$required, 'array', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_array($value)) {
                    return;
                }
                try {
                    new WorkingHoursCalendar((string) ($this->input('timezone') ?? $this->existingTimezone() ?? 'UTC'), $value);
                } catch (Throwable) {
                    $fail('Provide at least one valid, non-overlapping weekly working window.');
                }
            }],
            'is_default' => ['sometimes', 'boolean'],
        ];

        foreach (WorkingHoursCalendar::DAYS as $day) {
            $rules["weekly_hours.{$day}"] = ['sometimes', 'array'];
            // A `[start, end]` pair. `list` + `.*.*` instead of `.0`/`.1` rules: same checks, and the API
            // document gets an array of strings rather than an object with numeric keys.
            $rules["weekly_hours.{$day}.*"] = ['array', 'list', 'size:2'];
            $rules["weekly_hours.{$day}.*.*"] = ['required', 'string'];
        }

        return $rules;
    }

    private function existingTimezone(): ?string
    {
        $calendar = $this->route('calendar');

        return $calendar instanceof BusinessCalendar ? $calendar->timezone : null;
    }
}
