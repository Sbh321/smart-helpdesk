<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Modules\Audit\Audit;
use App\Modules\Billing\Enums\PaymentMethod;
use App\Modules\Billing\Enums\PaymentStatus;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\SubscriptionPayment;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;

/**
 * A platform admin records a payment received outside the app (cash at the office, a transfer the
 * workspace did not upload) on the workspace's behalf. The admin is the verifier, so it is approved
 * at once. MVP-SHORTCUT: no receipt file on this path; V1: attach a scan from the console (V1-PL-21).
 */
final readonly class RecordPayment
{
    public function __construct(private ReviewPayment $review) {}

    /**
     * @param  array{plan_id: string, periods: int, amount_minor: int, currency?: string|null, paid_on: string, method: string, reference?: string|null, note?: string|null}  $input
     */
    public function __invoke(Tenant $tenant, string $adminId, array $input): SubscriptionPayment
    {
        $plan = Plan::query()->whereKey($input['plan_id'])->firstOrFail();
        $payment = SubscriptionPayment::query()->create([
            'tenant_id' => $tenant->getKey(),
            'plan_id' => $plan->id,
            'periods' => $input['periods'],
            'amount_minor' => $input['amount_minor'],
            'currency' => $input['currency'] ?? $plan->currency,
            'paid_on' => CarbonImmutable::parse($input['paid_on'])->toDateString(),
            'method' => PaymentMethod::from($input['method']),
            'reference' => $input['reference'] ?? null,
            'note' => $input['note'] ?? null,
            'status' => PaymentStatus::Pending,
            'recorded_by_platform_user_id' => $adminId,
        ]);
        Audit::record('payment.recorded', $payment, ['plan' => $plan->code, 'amount_minor' => $payment->amount_minor], tenantId: null);

        return $this->review->approve($payment, $adminId);
    }
}
