<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Models;

use App\Models\User;
use App\Modules\Agents\Models\AgentProfile;
use App\Modules\Agents\Models\Team;
use App\Modules\Contacts\Concerns\HasTags;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Models\Organization;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Tickets\Domain\Priority;
use App\Modules\Tickets\Domain\TicketStatus;
use Carbon\CarbonImmutable;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A support request (docs/04-domain/tickets.md). `number` is the gapless per-workspace sequence;
 * `id` is what the API and URLs use.
 *
 * @property string $id
 * @property string $tenant_id
 * @property int $number
 * @property string $title
 * @property string $description
 * @property string $contact_id
 * @property string|null $organization_id
 * @property string $category_id
 * @property string|null $team_id
 * @property string|null $assigned_agent_id
 * @property TicketStatus $status
 * @property int $impact
 * @property int $urgency
 * @property string $priority_score
 * @property Priority $priority_level
 * @property Priority|null $priority_override_level
 * @property array<string, mixed> $priority_explanation
 * @property string|null $priority_override_reason
 * @property string|null $duplicate_of_id
 * @property int $reopen_count
 * @property int $version
 * @property string $created_via
 * @property CarbonImmutable|null $first_responded_at
 * @property CarbonImmutable|null $last_agent_reply_at
 * @property CarbonImmutable|null $last_customer_reply_at
 * @property CarbonImmutable|null $pending_since
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[UseFactory(TicketFactory::class)]
final class Ticket extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    use HasTags;
    use HasUuids;

    protected $guarded = ['id', 'tenant_id', 'number'];

    /** The generated full-text column is never read into PHP. */
    protected $hidden = ['search_vector'];

    /**
     * The level that applies: the manual override when there is one, otherwise the computed level.
     */
    public function effectivePriority(): Priority
    {
        return $this->priority_override_level ?? $this->priority_level;
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<AgentProfile, $this> */
    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(AgentProfile::class, 'assigned_agent_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * The newest assignment history row. A real relation, because AssignTicket attaches the row it
     * just wrote under this name and `refresh()` reloads every loaded relation.
     *
     * @return HasOne<TicketAssignment, $this>
     */
    public function latestAssignment(): HasOne
    {
        // Not latestOfMany(): it aggregates max(id), and PostgreSQL has no max() for uuid.
        return $this->hasOne(TicketAssignment::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * @return HasMany<TicketEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(TicketEvent::class);
    }

    /**
     * @return HasMany<TicketComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(TicketComment::class);
    }

    /** @return HasMany<TicketDuplicateSuggestion, $this> */
    public function duplicateSuggestions(): HasMany
    {
        return $this->hasMany(TicketDuplicateSuggestion::class);
    }

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'status' => TicketStatus::class,
            'impact' => 'integer',
            'urgency' => 'integer',
            'priority_level' => Priority::class,
            'priority_override_level' => Priority::class,
            'priority_explanation' => 'array',
            'first_responded_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'last_customer_reply_at' => 'immutable_datetime',
            'last_agent_reply_at' => 'immutable_datetime',
            'pending_since' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
