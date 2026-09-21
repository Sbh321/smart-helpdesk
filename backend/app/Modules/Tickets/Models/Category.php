<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Models;

use App\Modules\Agents\Models\Skill;
use App\Modules\Agents\Models\Team;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * What a ticket is about. Categories map to the skills needed to handle them, which the
 * assignment baseline uses (docs/05-algorithms/agent-assignment.md). Full CRUD arrives in M2-02.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $default_team_id
 * @property bool $is_active
 * @property int $sort_order
 */
#[UseFactory(CategoryFactory::class)]
final class Category extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    use HasUuids;

    protected $guarded = ['id', 'tenant_id'];

    /**
     * @return BelongsToMany<Skill, $this>
     */
    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class)->withPivot('tenant_id');
    }

    /** @return BelongsTo<Team, $this> */
    public function defaultTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'default_team_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }
}
