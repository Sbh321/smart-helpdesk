<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Requests;

use App\Modules\Billing\Enums\PlanKind;
use App\Modules\Billing\Models\Plan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create or update a plan. The code and kind are fixed once the plan exists; a trial has trial days
 * and no price, a paid plan has a period in months and a price.
 */
final class PlanRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Plan|null $plan */
        $plan = $this->route('plan');
        $kind = $plan?->kind->value ?? $this->input('kind');
        $creating = $plan === null;
        $required = $creating ? 'required' : 'sometimes';

        return [
            'code' => $creating
                ? ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]{1,39}$/', Rule::unique('plans', 'code')]
                : ['prohibited'],
            'kind' => $creating ? ['required', Rule::enum(PlanKind::class)] : ['prohibited'],
            'name' => [$required, 'string', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'price_minor' => $kind === PlanKind::Trial->value
                ? ['sometimes', 'integer', 'in:0']
                : [$required, 'integer', 'min:1', 'max:100000000000'],
            'currency' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'period_months' => $kind === PlanKind::Trial->value ? ['prohibited'] : [$required, 'integer', 'between:1,36'],
            'trial_days' => $kind === PlanKind::Trial->value ? [$required, 'integer', 'between:1,365'] : ['prohibited'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'between:0,1000'],
        ];
    }
}
