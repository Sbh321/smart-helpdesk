<?php

declare(strict_types=1);

namespace App\Modules\Sla\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Tickets\Domain\Priority;
use Database\Factories\SlaTargetFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $policy_id
 * @property Priority $priority_level
 * @property int $first_response_minutes
 * @property int $resolution_minutes
 */
#[UseFactory(SlaTargetFactory::class)]
final class SlaTarget extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<SlaTargetFactory> */
    use HasFactory;

    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['id', 'tenant_id'];

    /** @return BelongsTo<SlaPolicy, $this> */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(SlaPolicy::class, 'policy_id');
    }

    protected function casts(): array
    {
        return ['priority_level' => Priority::class, 'first_response_minutes' => 'integer', 'resolution_minutes' => 'integer'];
    }
}
