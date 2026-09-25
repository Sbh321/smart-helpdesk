<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Models\User;
use App\Modules\Audit\Audit;
use App\Modules\Billing\Enums\PaymentMethod;
use App\Modules\Billing\Enums\PaymentStatus;
use App\Modules\Billing\Events\PaymentSubmitted;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\SubscriptionPayment;
use App\Modules\Billing\Support\BillingException;
use App\Modules\Media\Models\Mediable;
use App\Modules\Media\Models\MediaItem;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Time\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A workspace sends a payment with its receipt for review (ADR-0025 §3, §4). Runs inside the
 * workspace: the receipt must be one of its ready media items, an image or a PDF, and is linked to the
 * payment so it cannot be trashed while in use. At most three payments wait for review at a time.
 */
final readonly class SubmitPayment
{
    public const MAX_PENDING = 3;

    public function __construct(private Clock $clock) {}

    /**
     * @param  array{plan_id: string, periods: int, amount_minor: int, currency?: string|null, paid_on: string, method: string, reference?: string|null, note?: string|null, receipt_media_id: string}  $input
     */
    public function __invoke(Tenant $tenant, User $user, array $input): SubscriptionPayment
    {
        $receipt = MediaItem::query()->whereKey($input['receipt_media_id'])->where('state', 'ready')->first();
        if ($receipt === null || ! (str_starts_with((string) $receipt->mime_type, 'image/') || $receipt->mime_type === 'application/pdf')) {
            throw BillingException::field('receipt_media_id', 'Upload the receipt as an image or a PDF.');
        }
        $plan = Plan::query()->whereKey($input['plan_id'])->where('is_active', true)->firstOrFail();
        $pending = SubscriptionPayment::query()->where('tenant_id', $tenant->getKey())->where('status', PaymentStatus::Pending)->count();
        if ($pending >= self::MAX_PENDING) {
            throw BillingException::field('receipt_media_id', 'Three payments are already waiting for review. Wait for them to be checked first.');
        }

        return DB::transaction(function () use ($tenant, $user, $input, $receipt, $plan): SubscriptionPayment {
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
                'receipt_media_id' => $receipt->id,
                'status' => PaymentStatus::Pending,
                'submitted_by_user_id' => $user->id,
                'submitted_by_name' => $user->name,
                'submitted_by_email' => $user->email,
            ]);
            Mediable::query()->create([
                'media_item_id' => $receipt->id,
                'mediable_type' => SubscriptionPayment::MEDIABLE_TYPE,
                'mediable_id' => $payment->id,
                'role' => 'receipt',
                'created_at' => $this->clock->now(),
            ]);
            Audit::record('payment.submitted', $payment, [
                'plan' => $plan->code, 'periods' => $payment->periods, 'amount_minor' => $payment->amount_minor, 'currency' => $payment->currency,
            ], tenantId: (string) $tenant->getKey());

            DB::afterCommit(fn () => PaymentSubmitted::dispatch($payment->id, (string) $tenant->name));

            return $payment;
        });
    }
}
