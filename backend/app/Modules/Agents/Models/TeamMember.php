<?php

declare(strict_types=1);

namespace App\Modules\Agents\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\TeamMemberFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[UseFactory(TeamMemberFactory::class)]
final class TeamMember extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<TeamMemberFactory> */
    use HasFactory;

    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['id', 'tenant_id'];

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<AgentProfile, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(AgentProfile::class, 'agent_profile_id');
    }

    protected function casts(): array
    {
        return ['joined_at' => 'immutable_datetime'];
    }
}
