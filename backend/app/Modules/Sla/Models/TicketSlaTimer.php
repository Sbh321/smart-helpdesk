<?php

declare(strict_types=1);

namespace App\Modules\Sla\Models;

use App\Modules\Sla\Domain\Timer\TimerKind;
use App\Modules\Sla\Domain\Timer\TimerState;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Tickets\Models\Ticket;
use Carbon\CarbonImmutable;
use Database\Factories\TicketSlaTimerFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $ticket_id
 * @property string $policy_id
 * @property int $policy_version
 * @property string $warning_fraction
 * @property TimerKind $kind
 * @property int $cycle
 * @property TimerState $state
 * @property TimerState|null $paused_from_state
 * @property int $target_minutes
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable $warning_at
 * @property CarbonImmutable $due_at
 * @property CarbonImmutable|null $paused_at
 * @property int $paused_total_seconds
 * @property CarbonImmutable|null $warned_at
 * @property CarbonImmutable|null $breached_at
 * @property CarbonImmutable|null $met_at
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $calendar_id
 * @property string $strategy
 * @property string $strategy_version
 */
#[UseFactory(TicketSlaTimerFactory::class)]
final class TicketSlaTimer extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<TicketSlaTimerFactory> */
    use HasFactory;

    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['id', 'tenant_id'];

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<SlaPolicy, $this> */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(SlaPolicy::class, 'policy_id');
    }

    /** @return HasMany<SlaEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(SlaEvent::class, 'timer_id');
    }

    protected function casts(): array
    {
        return [
            'kind' => TimerKind::class, 'state' => TimerState::class, 'paused_from_state' => TimerState::class,
            'policy_version' => 'integer', 'warning_fraction' => 'decimal:2', 'cycle' => 'integer', 'target_minutes' => 'integer',
            'paused_total_seconds' => 'integer', 'started_at' => 'immutable_datetime',
            'warning_at' => 'immutable_datetime', 'due_at' => 'immutable_datetime',
            'paused_at' => 'immutable_datetime', 'warned_at' => 'immutable_datetime',
            'breached_at' => 'immutable_datetime', 'met_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }
}
