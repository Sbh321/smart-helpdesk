<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Resources;

use App\Modules\Billing\Models\SubscriptionPayment;
use App\Modules\Media\Models\MediaItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A payment and its review (ADR-0025 §3). `receipt` is the media item's summary (open it through the
 * media endpoints in the workspace, or `GET /platform-api/payments/{id}/receipt` in the console);
 * `workspace` is present in the console only.
 *
 * @mixin SubscriptionPayment
 */
final class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var SubscriptionPayment $payment */
        $payment = $this->resource;
        /** @var MediaItem|null $receipt */
        $receipt = $payment->relationLoaded('receipt') ? $payment->getRelation('receipt') : null;

        return [
            'id' => $this->id,
            'plan' => new PlanResource($this->plan),
            'periods' => $this->periods,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            /** What the plan asks for these periods, to compare with `amount_minor`. */
            'expected_minor' => $this->plan->price_minor * $this->periods,
            'paid_on' => $this->paid_on->toDateString(),
            /** @var 'bank_transfer'|'wallet'|'cash'|'other' */
            'method' => $this->method->value,
            'reference' => $this->reference,
            'note' => $this->note,
            /** @var 'pending'|'approved'|'rejected' */
            'status' => $this->status->value,
            'rejection_reason' => $this->rejection_reason,
            'submitted_by' => $this->submitted_by_email === null ? null : ['name' => $this->submitted_by_name, 'email' => $this->submitted_by_email],
            /** True when a platform admin recorded it for the workspace. */
            'recorded_by_platform' => $this->recorded_by_platform_user_id !== null,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'period_starts_at' => $this->period_starts_at?->toIso8601String(),
            'period_ends_at' => $this->period_ends_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            /** @var array{id: string, name: string, mime_type: string, size_bytes: int, width: int|null, height: int|null, has_thumb: bool, has_preview: bool}|null */
            'receipt' => $receipt === null ? null : [
                'id' => $receipt->id,
                'name' => $receipt->name,
                'mime_type' => $receipt->mime_type,
                'size_bytes' => $receipt->size_bytes,
                'width' => $receipt->width,
                'height' => $receipt->height,
                'has_thumb' => is_array($receipt->variants['thumb'] ?? null),
                'has_preview' => is_array($receipt->variants['preview'] ?? null),
            ],
            'workspace' => $this->when($payment->relationLoaded('tenant') && $request->is('platform-api/*'), fn (): array => [
                'id' => $this->tenant->id,
                'slug' => $this->tenant->slug,
                'name' => $this->tenant->name,
            ]),
        ];
    }
}
