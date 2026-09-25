<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Modules\Audit\Audit;
use App\Modules\Billing\Enums\PaymentStatus;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Models\SubscriptionPayment;
use App\Modules\Billing\Notifications\PaymentApproved;
use App\Modules\Billing\Notifications\PaymentRejected;
use App\Modules\Billing\Support\BillingContacts;
use App\Modules\Billing\Support\BillingException;
use App\Support\Errors\ErrorCode;
use App\Support\Time\Clock;
use Illuminate\Support\Facades\DB;

/**
 * A platform admin decides a pending payment (ADR-0025 §3). Approval extends the subscription by the
 * paid periods from the later of now and its current end, switches it to the paid plan, and records
 * the period on the payment; rejection keeps a reason. Each runs once, under row locks: a second
 * decision is refused with 409 `already_reviewed`. The workspace's billing people are emailed.
 */
final readonly class ReviewPayment
{
    public function __construct(private Clock $clock, private BillingContacts $contacts) {}

    public function approve(SubscriptionPayment $payment, string $reviewerId): SubscriptionPayment
    {
        $payment = DB::transaction(function () use ($payment, $reviewerId): SubscriptionPayment {
            $payment = $this->lockPending($payment);
            $now = $this->clock->now();
            $subscription = Subscription::query()->where('tenant_id', $payment->tenant_id)->lockForUpdate()->first()
                ?? new Subscription(['tenant_id' => $payment->tenant_id, 'ends_at' => $now]);

            $start = $subscription->ends_at->greaterThan($now) ? $subscription->ends_at : $now;
            $end = $start->addMonthsNoOverflow($payment->periods * (int) $payment->plan->period_months);
            $subscription->forceFill(['plan_id' => $payment->plan_id, 'ends_at' => $end, 'reminders' => []])->save();

            $payment->forceFill([
                'status' => PaymentStatus::Approved,
                'reviewed_by_platform_user_id' => $reviewerId,
                'reviewed_at' => $now,
                'period_starts_at' => $start,
                'period_ends_at' => $end,
            ])->save();
            Audit::record('payment.approved', $payment, [
                'period_starts_at' => $start->toIso8601String(), 'period_ends_at' => $end->toIso8601String(),
            ], tenantId: null);

            return $payment;
        });

        $this->contacts->notify($payment->tenant, new PaymentApproved($payment));

        return $payment;
    }

    public function reject(SubscriptionPayment $payment, string $reviewerId, string $reason): SubscriptionPayment
    {
        $payment = DB::transaction(function () use ($payment, $reviewerId, $reason): SubscriptionPayment {
            $payment = $this->lockPending($payment);
            $payment->forceFill([
                'status' => PaymentStatus::Rejected,
                'rejection_reason' => $reason,
                'reviewed_by_platform_user_id' => $reviewerId,
                'reviewed_at' => $this->clock->now(),
            ])->save();
            Audit::record('payment.rejected', $payment, ['reason' => $reason], tenantId: null);

            return $payment;
        });

        $this->contacts->notify($payment->tenant, new PaymentRejected($payment));

        return $payment;
    }

    private function lockPending(SubscriptionPayment $payment): SubscriptionPayment
    {
        $locked = SubscriptionPayment::query()->with(['plan', 'tenant'])->whereKey($payment->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== PaymentStatus::Pending) {
            throw new BillingException(ErrorCode::AlreadyReviewed, "This payment was already {$locked->status->value}.");
        }

        return $locked;
    }
}
