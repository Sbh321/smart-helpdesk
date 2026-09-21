<?php

declare(strict_types=1);

namespace App\Modules\Sla\Models;

use App\Modules\Contacts\Enums\OrganizationTier;
use App\Modules\Sla\Exceptions\SlaTargetMissing;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Tickets\Domain\Priority;
use Database\Factories\SlaPolicyFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property bool $is_default
 * @property OrganizationTier|null $applies_to_tier
 * @property string $warning_fraction
 * @property string|null $calendar_id
 * @property int $version
 */
#[UseFactory(SlaPolicyFactory::class)]
final class SlaPolicy extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<SlaPolicyFactory> */
    use HasFactory;

    use HasUuids;

    protected $guarded = ['id', 'tenant_id'];

    /** @return BelongsTo<BusinessCalendar, $this> */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(BusinessCalendar::class, 'calendar_id');
    }

    /** @return HasMany<SlaTarget, $this> */
    public function targets(): HasMany
    {
        return $this->hasMany(SlaTarget::class, 'policy_id');
    }

    /**
     * @throws SlaTargetMissing when the policy has no row for that priority
     */
    public function targetFor(Priority $priority): SlaTarget
    {
        return $this->targets()->where('priority_level', $priority->value)->first()
            ?? throw SlaTargetMissing::for($this, $priority);
    }

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'applies_to_tier' => OrganizationTier::class, 'warning_fraction' => 'decimal:2', 'version' => 'integer'];
    }
}
