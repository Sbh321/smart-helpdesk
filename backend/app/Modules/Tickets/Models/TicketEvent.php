<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One entry of a ticket's domain history (docs/04-domain/audit.md §Domain history). Append-only.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $ticket_id
 * @property string $type
 * @property string $actor_type
 * @property string|null $actor_id
 * @property array<string, mixed> $old_values
 * @property array<string, mixed> $new_values
 * @property string|null $note
 * @property CarbonImmutable $created_at
 */
final class TicketEvent extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['id', 'tenant_id'];

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('Ticket history is append-only.'));
        self::deleting(fn (): never => throw new LogicException('Ticket history is append-only.'));
    }

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
