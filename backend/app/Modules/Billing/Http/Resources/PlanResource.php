<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Resources;

use App\Modules\Billing\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A plan. `subscriptions_count` is present in the console's plan list only.
 *
 * @mixin Plan
 */
final class PlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            /** @var 'trial'|'paid' */
            'kind' => $this->kind->value,
            /** Price of one period in minor units (paisa); 0 for a trial. */
            'price_minor' => $this->price_minor,
            'currency' => $this->currency,
            'period_months' => $this->period_months,
            'trial_days' => $this->trial_days,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'subscriptions_count' => $this->whenCounted('subscriptions'),
        ];
    }
}
