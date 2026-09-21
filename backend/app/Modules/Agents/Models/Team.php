<?php

declare(strict_types=1);

namespace App\Modules\Agents\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $description
 * @property-read Collection<int, AgentProfile> $agents
 */
#[UseFactory(TeamFactory::class)]
final class Team extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    use HasUuids;

    protected $guarded = ['id', 'tenant_id'];

    /** @return BelongsToMany<AgentProfile, $this> */
    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(AgentProfile::class, 'team_members')->withPivot(['tenant_id', 'joined_at']);
    }
}
