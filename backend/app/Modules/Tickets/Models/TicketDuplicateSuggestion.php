<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\TicketDuplicateSuggestionFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $ticket_id
 * @property string $candidate_ticket_id
 * @property float $score
 * @property array<string, mixed> $breakdown
 * @property string $decision
 * @property string|null $decided_by_user_id
 * @property CarbonImmutable|null $decided_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[UseFactory(TicketDuplicateSuggestionFactory::class)]
final class TicketDuplicateSuggestion extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<TicketDuplicateSuggestionFactory> */
    use HasFactory;

    use HasUuids;

    protected $guarded = ['id', 'tenant_id'];

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<Ticket, $this> */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'candidate_ticket_id');
    }

    protected function casts(): array
    {
        return ['score' => 'float', 'breakdown' => 'array', 'decided_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }
}
