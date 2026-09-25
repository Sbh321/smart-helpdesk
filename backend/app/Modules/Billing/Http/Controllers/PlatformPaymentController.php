<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers;

use App\Modules\Billing\Actions\RecordPayment;
use App\Modules\Billing\Actions\ReviewPayment;
use App\Modules\Billing\Enums\PaymentStatus;
use App\Modules\Billing\Http\Requests\PaymentRequest;
use App\Modules\Billing\Http\Resources\PaymentResource;
use App\Modules\Billing\Models\SubscriptionPayment;
use App\Modules\Billing\Support\Receipts;
use App\Modules\Tenancy\Models\Tenant;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Payments for platform super admins: the review queue and payments recorded by hand (ADR-0025 §3). */
#[Group('Platform: billing')]
final class PlatformPaymentController
{
    /**
     * List payments.
     *
     * Pending payments come oldest first (the review queue); the others newest first.
     */
    #[QueryParameter('status', 'pending, approved or rejected.', type: 'string')]
    #[QueryParameter('tenant_id', 'One workspace.', type: 'string')]
    public function index(Request $request, Receipts $receipts): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['sometimes', Rule::enum(PaymentStatus::class)],
            'tenant_id' => ['sometimes', 'uuid'],
        ]);
        $status = $request->string('status')->value();
        $payments = SubscriptionPayment::query()->with(['plan', 'tenant'])
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($request->filled('tenant_id'), fn ($query) => $query->where('tenant_id', $request->string('tenant_id')->value()))
            ->orderBy('created_at', $status === PaymentStatus::Pending->value ? 'asc' : 'desc')
            ->paginate(perPage: min(100, max(1, $request->integer('per_page', 25))));
        $receipts->attach($payments->items());

        return PaymentResource::collection($payments);
    }

    /** Show a payment. */
    public function show(SubscriptionPayment $payment, Receipts $receipts): PaymentResource
    {
        $payment->load(['plan', 'tenant']);
        $receipts->attach([$payment]);

        return new PaymentResource($payment);
    }

    /**
     * Open a payment's receipt.
     *
     * Redirects to a short-lived URL that shows the receipt in the browser.
     */
    public function receipt(SubscriptionPayment $payment, Receipts $receipts): RedirectResponse
    {
        $url = $receipts->openUrl($payment->load('tenant'));
        abort_if($url === null, 404);

        return redirect()->away($url);
    }

    /** Approve a payment: the subscription is extended at once. */
    public function approve(Request $request, SubscriptionPayment $payment, ReviewPayment $review, Receipts $receipts): PaymentResource
    {
        $payment = $review->approve($payment, (string) $request->user('platform')?->getAuthIdentifier());
        $receipts->attach([$payment]);

        return new PaymentResource($payment);
    }

    /** Reject a payment, with a reason the workspace will read. */
    public function reject(Request $request, SubscriptionPayment $payment, ReviewPayment $review, Receipts $receipts): PaymentResource
    {
        /** @var array{reason: string} $data */
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $payment = $review->reject($payment, (string) $request->user('platform')?->getAuthIdentifier(), $data['reason']);
        $receipts->attach([$payment]);

        return new PaymentResource($payment);
    }

    /** Record a payment received outside the app; it is approved at once. */
    #[Response(status: 201, type: PaymentResource::class)]
    public function store(PaymentRequest $request, Tenant $tenant, RecordPayment $record): JsonResponse
    {
        /** @var array{plan_id: string, periods: int, amount_minor: int, currency?: string|null, paid_on: string, method: string, reference?: string|null, note?: string|null} $input */
        $input = $request->validated();
        $payment = $record($tenant, (string) $request->user('platform')?->getAuthIdentifier(), $input);
        $payment->setRelation('receipt', null);

        return (new PaymentResource($payment->load(['plan', 'tenant'])))->response()->setStatusCode(201);
    }
}
