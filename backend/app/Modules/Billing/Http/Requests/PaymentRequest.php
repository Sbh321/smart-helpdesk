<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Requests;

use App\Modules\Billing\Enums\PaymentMethod;
use App\Modules\Billing\Enums\PlanKind;
use App\Support\Time\Clock;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A payment for some periods of a paid plan: sent by a workspace with its receipt, or recorded by a
 * platform admin (no receipt). Amounts are integers in minor units.
 */
final class PaymentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $fromWorkspace = ! $this->is('platform-api/*');
        // "Today" from the Clock, a day ahead of UTC so a payment made this morning in Asia is not "future".
        $latest = app(Clock::class)->now()->addDay()->toDateString();

        return [
            'plan_id' => ['required', 'uuid', Rule::exists('plans', 'id')->where('kind', PlanKind::Paid->value)->where('is_active', true)],
            'periods' => ['required', 'integer', 'between:1,36'],
            'amount_minor' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$latest, 'after:2020-01-01'],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'reference' => ['sometimes', 'nullable', 'string', 'max:120'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'receipt_media_id' => $fromWorkspace ? ['required', 'uuid'] : ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'plan_id.exists' => 'Choose one of the plans on offer.',
            'receipt_media_id.required' => 'Upload the receipt.',
            'paid_on.before_or_equal' => 'The payment date cannot be in the future.',
        ];
    }
}
