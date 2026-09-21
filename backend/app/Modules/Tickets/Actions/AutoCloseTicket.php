<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Actions;

use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Events\TicketLifecycleChanged;
use App\Modules\Tickets\Events\TicketStatusChanged;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketEvent;
use App\Support\Time\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Closes one resolved ticket on behalf of the system (docs/11-operations/scheduler.md
 * `tickets:auto-close`). The same writes as a manual close in `TransitionTicket`, with actor `system`
 * and no permission check. A ticket that changed since it was selected (reopened, closed, a new
 * resolution) is left alone.
 */
final readonly class AutoCloseTicket
{
    public function __construct(private Clock $clock) {}

    /** @return bool whether the ticket was closed */
    public function __invoke(string $ticketId, CarbonImmutable $resolvedBefore): bool
    {
        return DB::transaction(function () use ($ticketId, $resolvedBefore): bool {
            $locked = Ticket::query()->whereKey($ticketId)->lockForUpdate()->first();
            if ($locked === null
                || $locked->status !== TicketStatus::Resolved
                || $locked->resolved_at === null
                || $locked->resolved_at->greaterThan($resolvedBefore)) {
                return false;
            }

            $now = $this->clock->now();
            $locked->status = TicketStatus::Resolved->transitionTo(TicketStatus::Closed);
            $locked->closed_at = $now;
            $locked->pending_since = null;
            $locked->version = (int) $locked->version + 1;
            $locked->updated_at = $now;
            $locked->save();

            TicketEvent::query()->create([
                'ticket_id' => $locked->id,
                'type' => 'status_changed',
                'actor_type' => 'system',
                'actor_id' => null,
                'old_values' => ['status' => TicketStatus::Resolved->value],
                'new_values' => ['status' => TicketStatus::Closed->value, 'closed_at' => $now->toIso8601ZuluString()],
                'note' => 'Closed automatically after the resolution period',
                'created_at' => $now,
            ]);

            event(new TicketLifecycleChanged($locked->tenant_id, $locked->id, TicketStatus::Resolved->value, TicketStatus::Closed->value));
            event(new TicketStatusChanged($locked->tenant_id, $locked->id, null, TicketStatus::Resolved->value, TicketStatus::Closed->value));

            return true;
        });
    }
}
