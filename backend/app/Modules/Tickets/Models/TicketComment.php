<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
 */
final class TicketComment extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['id', 'tenant_id'];

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    protected function casts(): array
    {
        return ['edited_at' => 'immutable_datetime'];
    }
}
