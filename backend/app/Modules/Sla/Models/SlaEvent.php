<?php

declare(strict_types=1);

namespace App\Modules\Sla\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Tickets\Models\Ticket;
use Database\Factories\SlaEventFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[UseFactory(SlaEventFactory::class)]
final class SlaEvent extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<SlaEventFactory> */
    use HasFactory;

    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['id', 'tenant_id'];

    /** @return BelongsTo<TicketSlaTimer, $this> */
    public function timer(): BelongsTo
    {
        return $this->belongsTo(TicketSlaTimer::class, 'timer_id');
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    protected function casts(): array
    {
        return ['payload' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
