<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Models;

use App\Modules\Media\Models\Mediable;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\TicketCommentFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A public reply or internal note. Writing comments is M2 work; the table exists now so the
 * schema matches docs/08-database/entities.md.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $ticket_id
 * @property string $visibility
 * @property string $author_type
 * @property string|null $author_id
 * @property string $body
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[UseFactory(TicketCommentFactory::class)]
final class TicketComment extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<TicketCommentFactory> */
    use HasFactory;

    use HasUuids;

    protected $guarded = ['id', 'tenant_id'];

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return HasMany<Mediable, $this> */
    public function mediaLinks(): HasMany
    {
        return $this->hasMany(Mediable::class, 'mediable_id')->where('mediable_type', 'ticket_comment');
    }

    protected function casts(): array
    {
        return ['edited_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }
}
