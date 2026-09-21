<?php

declare(strict_types=1);

namespace App\Modules\Agents\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\AgentSkillFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[UseFactory(AgentSkillFactory::class)]
final class AgentSkill extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<AgentSkillFactory> */
    use HasFactory;

    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['id', 'tenant_id'];

    /** @return BelongsTo<AgentProfile, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(AgentProfile::class, 'agent_profile_id');
    }

    /** @return BelongsTo<Skill, $this> */
    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    protected function casts(): array
    {
        return ['level' => 'integer'];
    }
}
