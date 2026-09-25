<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Modules\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A workspace's plan and end date (ADR-0025 §2). Central, keyed by `tenant_id` without row-level
 * security: workspace endpoints must filter by the resolved tenant themselves. Its state is derived
 * by `SubscriptionStatus`, never stored.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $plan_id
 * @property CarbonImmutable $ends_at
 * @property list<string> $reminders
 * @property-read Plan $plan
 * @property-read Tenant $tenant
 */
final class Subscription extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public static function forTenant(string $tenantId): ?self
    {
        return self::query()->with('plan')->where('tenant_id', $tenantId)->first();
    }

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
            'ends_at' => 'immutable_datetime',
            'reminders' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
