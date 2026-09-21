<?php

declare(strict_types=1);

namespace App\Modules\Agents\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\AgentShiftFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $agent_profile_id
 * @property int|null $weekday
 * @property CarbonImmutable|null $date
 * @property string $starts_at
 * @property string $ends_at
 * @property bool $is_off
 */
#[UseFactory(AgentShiftFactory::class)]
final class AgentShift extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<AgentShiftFactory> */
    use HasFactory;

    use HasUuids;

    protected $guarded = ['id', 'tenant_id'];

    /** @return BelongsTo<AgentProfile, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(AgentProfile::class, 'agent_profile_id');
    }

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'date' => 'immutable_date',
            'is_off' => 'boolean',
        ];
    }
}
