<?php

declare(strict_types=1);

namespace App\Modules\Agents\Models;

use App\Models\User;
use App\Modules\Agents\Enums\AgentAvailability;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\AgentProfileFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property int $capacity
 * @property AgentAvailability $availability
 * @property int $active_ticket_count stored workload; written only by Automation (AgentWorkloadCounter, agents:reconcile-workload)
 * @property CarbonImmutable|null $last_assigned_at
 * @property-read User $user
 * @property-read Collection<int, Team> $teams
 * @property-read Collection<int, Skill> $skills
 * @property-read Collection<int, AgentShift> $shifts
 */
#[UseFactory(AgentProfileFactory::class)]
final class AgentProfile extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<AgentProfileFactory> */
    use HasFactory;

    use HasUuids;

    protected $guarded = ['id', 'tenant_id'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsToMany<Team, $this> */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_members')->withPivot(['tenant_id', 'joined_at']);
    }

    /** @return BelongsToMany<Skill, $this> */
    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class, 'agent_skills')->withPivot(['tenant_id', 'level']);
    }

    /** @return HasMany<AgentShift, $this> */
    public function shifts(): HasMany
    {
        return $this->hasMany(AgentShift::class);
    }

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'availability' => AgentAvailability::class,
            'active_ticket_count' => 'integer',
            'last_assigned_at' => 'immutable_datetime',
        ];
    }
}
