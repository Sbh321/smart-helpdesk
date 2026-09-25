<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers;

use App\Models\User;
use App\Modules\Billing\Actions\SubmitPayment;
use App\Modules\Billing\Enums\PlanKind;
use App\Modules\Billing\Http\Requests\PaymentRequest;
use App\Modules\Billing\Http\Resources\PaymentResource;
use App\Modules\Billing\Http\Resources\PlanResource;
use App\Modules\Billing\Http\Resources\SubscriptionResource;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\SubscriptionPayment;
use App\Modules\Billing\Support\BillingSettings;
use App\Modules\Billing\Support\Receipts;
use App\Modules\Billing\Support\Subscriptions;
use App\Modules\Tenancy\Models\Tenant;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

/**
 * The workspace's own billing (ADR-0025 §3, §6). Subscriptions and payments are central rows keyed by
 * tenant without row-level security, so every query here filters by the resolved workspace itself.
 */
#[Group('Billing')]
final class BillingController
{
    /**
     * Show the workspace's subscription, the plans on offer and its payments.
     *
     * @response array{data: array{subscription: SubscriptionResource, plans: list<PlanResource>, payments: list<PaymentResource>, grace_days: int, payment_instructions: string}}
     */
    public function show(Subscriptions $subscriptions, Receipts $receipts, BillingSettings $settings): JsonResponse
    {
        $tenantId = self::tenantId();
        $payments = SubscriptionPayment::query()->with('plan')->where('tenant_id', $tenantId)->latest()->limit(50)->get();
        $receipts->attach($payments);

        return response()->json(['data' => [
            'subscription' => new SubscriptionResource($subscriptions->statusOf($tenantId)),
            'plans' => PlanResource::collection(Plan::query()->where('kind', PlanKind::Paid)->where('is_active', true)->ordered()->get()),
            'payments' => PaymentResource::collection($payments),
            'grace_days' => $settings->graceDays(),
            // Where to pay: set by the platform admins (bank account, wallet id).
            'payment_instructions' => $settings->paymentInstructions(),
        ]]);
    }

    /**
     * Send a payment for review.
     *
     * Upload the receipt first (`POST /media/intent` with `purpose: receipt`), then send its id here.
     * A platform admin approves or rejects it; the workspace's billing people are emailed either way.
     */
    #[Response(status: 201, type: PaymentResource::class)]
    public function store(PaymentRequest $request, SubmitPayment $submit, Receipts $receipts): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var Tenant $tenant */
        $tenant = tenant();
        /** @var array{plan_id: string, periods: int, amount_minor: int, currency?: string|null, paid_on: string, method: string, reference?: string|null, note?: string|null, receipt_media_id: string} $input */
        $input = $request->validated();
        $payment = $submit($tenant, $user, $input)->load('plan');
        $receipts->attach([$payment]);

        return (new PaymentResource($payment))->response()->setStatusCode(201);
    }

    private static function tenantId(): string
    {
        return (string) tenant()?->getKey();
    }
}
