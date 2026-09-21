<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Enums\TenantStatus;
use Carbon\CarbonImmutable;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * A customer organisation (workspace). Control-plane model: no tenant_id, no RLS.
 *
 * @property string $id
 * @property string $slug
 * @property string $name
 * @property TenantStatus $status
 * @property string $plan
 * @property string $placement
 * @property string|null $owner_email
 * @property string $timezone
 * @property CarbonImmutable|null $suspended_at
 * @property CarbonImmutable|null $archived_at
 */
#[UseFactory(TenantFactory::class)]
final class Tenant extends BaseTenant
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * Real columns; anything else would go to stancl's `data` JSON column.
     *
     * @return list<string>
     */
    public static function getCustomColumns(): array
    {
        return [
            'id', 'slug', 'name', 'status', 'plan', 'placement', 'owner_email', 'timezone',
            'suspended_at', 'archived_at', 'storage_quota_bytes', 'created_at', 'updated_at',
        ];
    }

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'suspended_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === TenantStatus::Active;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', TenantStatus::Active->value);
    }

    public static function findBySlug(string $slug): ?self
    {
        return self::query()->where('slug', mb_strtolower($slug))->first();
    }

    /**
     * @return HasMany<Domain, $this>
     */
    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class, 'tenant_id');
    }

    /**
     * @return HasOne<TenantCounter, $this>
     */
    public function counter(): HasOne
    {
        return $this->hasOne(TenantCounter::class, 'tenant_id');
    }
}
