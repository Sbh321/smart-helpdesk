<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Modules\Billing\Enums\PlanKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A plan a workspace can be on (ADR-0025 §1). Control plane: no tenant.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property PlanKind $kind
 * @property int $price_minor
 * @property string $currency
 * @property int|null $period_months
 * @property int|null $trial_days
 * @property bool $is_active
 * @property int $sort_order
 * @property CarbonImmutable|null $created_at
 */
final class Plan extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    /** The active trial plan: what a new workspace starts on. */
    public static function activeTrial(): ?self
    {
        return self::query()->where('kind', PlanKind::Trial)->where('is_active', true)->first();
    }

    /** @param Builder<self> $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function isTrial(): bool
    {
        return $this->kind === PlanKind::Trial;
    }

    protected function casts(): array
    {
        return [
            'kind' => PlanKind::class,
            'price_minor' => 'integer',
            'period_months' => 'integer',
            'trial_days' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
