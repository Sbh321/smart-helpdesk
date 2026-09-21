<?php

declare(strict_types=1);

namespace App\Modules\Sla\Http\Requests;

use App\Modules\Sla\Models\SlaPolicy;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SavePolicyRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:80', function (string $attribute, mixed $value, Closure $fail): void {
                $policy = $this->route('policy');
                if (is_string($value) && SlaPolicy::query()->whereRaw('lower(name) = lower(?)', [$value])
                    ->when($policy instanceof SlaPolicy, fn ($query) => $query->whereKeyNot($policy->id))->exists()) {
                    $fail('An SLA policy with this name already exists.');
                }
            }],
            'is_default' => ['sometimes', 'boolean'],
            'applies_to_tier' => ['sometimes', 'nullable', Rule::in(['standard', 'premium', 'enterprise']), function (string $attribute, mixed $value, Closure $fail): void {
                $policy = $this->route('policy');
                if (is_string($value) && ($this->boolean('is_default') || ($policy instanceof SlaPolicy && $policy->is_default))) {
                    $fail('The default SLA policy must apply to all Organisation tiers.');
                }
                if (is_string($value) && SlaPolicy::query()->where('applies_to_tier', $value)
                    ->when($policy instanceof SlaPolicy, fn ($query) => $query->whereKeyNot($policy->id))->exists()) {
                    $fail('An SLA policy for this Organisation tier already exists.');
                }
            }],
            'warning_fraction' => [$required, 'numeric', 'between:0.10,0.95'],
            'calendar_id' => ['sometimes', 'nullable', Rule::exists('business_calendars', 'id')->where('tenant_id', tenant('id'))],
            'targets' => [$required, 'array', 'size:4'],
            'targets.*.priority_level' => ['required', Rule::in(['P1', 'P2', 'P3', 'P4']), 'distinct'],
            'targets.*.first_response_minutes' => ['required', 'integer', 'min:1', 'max:525600'],
            'targets.*.resolution_minutes' => ['required', 'integer', 'min:1', 'max:525600'],
        ];
    }
}
