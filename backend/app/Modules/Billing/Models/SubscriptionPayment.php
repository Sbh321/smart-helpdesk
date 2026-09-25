<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Modules\Billing\Enums\PaymentMethod;
use App\Modules\Billing\Enums\PaymentStatus;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A payment for some periods of a plan, proven by a receipt and reviewed by a platform admin
 * (ADR-0025 §3). Central, keyed by `tenant_id` without row-level security, like `Subscription`.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $plan_id
 * @property int $periods
 * @property int $amount_minor
 * @property string $currency
 * @property CarbonImmutable $paid_on
 * @property PaymentMethod $method
 * @property string|null $reference
 * @property string|null $note
 * @property string|null $receipt_media_id
 * @property PaymentStatus $status
 * @property string|null $rejection_reason
 * @property string|null $submitted_by_user_id
 * @property string|null $submitted_by_name
 * @property string|null $submitted_by_email
 * @property string|null $recorded_by_platform_user_id
 * @property string|null $reviewed_by_platform_user_id
 * @property CarbonImmutable|null $reviewed_at
 * @property CarbonImmutable|null $period_starts_at
 * @property CarbonImmutable|null $period_ends_at
 * @property CarbonImmutable|null $created_at
 * @property-read Plan $plan
 * @property-read Tenant $tenant
 */
final class SubscriptionPayment extends Model
{
    use HasUuids;

    /** The `mediables.mediable_type` that links a receipt to its payment. */
    public const MEDIABLE_TYPE = 'subscription_payment';

    protected $guarded = ['id'];

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    protected function casts(): array
    {
        return [
            'periods' => 'integer',
            'amount_minor' => 'integer',
            'paid_on' => 'immutable_date',
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'reviewed_at' => 'immutable_datetime',
            'period_starts_at' => 'immutable_datetime',
            'period_ends_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
