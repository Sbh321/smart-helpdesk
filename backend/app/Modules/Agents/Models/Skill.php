<?php

declare(strict_types=1);

namespace App\Modules\Agents\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\SkillFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property-read Pivot $pivot
 */
#[UseFactory(SkillFactory::class)]
final class Skill extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<SkillFactory> */
    use HasFactory;

    use HasUuids;

    protected $guarded = ['id', 'tenant_id'];

    /** @return BelongsToMany<AgentProfile, $this> */
    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(AgentProfile::class, 'agent_skills')->withPivot(['tenant_id', 'level']);
    }
}
