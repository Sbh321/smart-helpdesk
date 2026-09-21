<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Queries;

use App\Modules\Tenancy\Settings\Settings;
use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Models\Ticket;
use App\Support\Time\Clock;
use Illuminate\Contracts\Auth\Access\Authorizable;

/** Permission- and persistence-aware lifecycle targets exposed by the ticket API. */
final readonly class TicketTransitionRules
{
    public function __construct(private Clock $clock, private Settings $settings) {}

    /**
     * @return list<TicketStatus>
     */
    public function safeTargets(Ticket $ticket): array
    {
        $targets = array_values(array_filter(
            $ticket->status->allowedTargets(),
            fn (TicketStatus $target): bool => ! $this->belongsToDedicatedAction($ticket->status, $target),
        ));

        if (! $this->canReopen($ticket)) {
            $targets = array_values(array_filter(
                $targets,
                fn (TicketStatus $target): bool => ! $this->isReopen($ticket->status, $target),
            ));
        }

        return $targets;
    }

    /**
     * @return list<TicketStatus>
     */
    public function allowedTargets(Ticket $ticket, ?Authorizable $actor): array
    {
        if ($actor === null || ! $actor->can('tickets.update')) {
            return [];
        }

        return array_values(array_filter(
            $this->safeTargets($ticket),
            fn (TicketStatus $target): bool => $this->hasTargetPermission($ticket->status, $target, $actor),
        ));
    }

    public function hasTargetPermission(TicketStatus $from, TicketStatus $to, Authorizable $actor): bool
    {
        return match (true) {
            $to === TicketStatus::Resolved => $actor->can('tickets.resolve'),
            $to === TicketStatus::Closed => $actor->can('tickets.close'),
            // Reopening a resolved ticket is ordinary agent work; only closed tickets need the
            // extra permission (docs/04-domain/tickets.md §Transition rules).
            $from === TicketStatus::Closed && $this->isReopen($from, $to) => $actor->can('tickets.reopen'),
            default => true,
        };
    }

    public function canReopen(Ticket $ticket): bool
    {
        // A ticket closed as a duplicate points at its original; reopening it would leave that link
        // and the accepted suggestion in place. It stays closed (V1: an explicit unmark action).
        if ($ticket->duplicate_of_id !== null) {
            return false;
        }

        if (! in_array($ticket->status, [TicketStatus::Resolved, TicketStatus::Closed], true) || $ticket->resolved_at === null) {
            return true;
        }

        $days = max(0, (int) $this->settings->get('tickets.reopen_window_days', 14));

        return $this->clock->now()->lessThanOrEqualTo($ticket->resolved_at->addDays($days));
    }

    public function isReopen(TicketStatus $from, TicketStatus $to): bool
    {
        return in_array($from, [TicketStatus::Resolved, TicketStatus::Closed], true)
            && $to === TicketStatus::InProgress;
    }

    private function belongsToDedicatedAction(TicketStatus $from, TicketStatus $to): bool
    {
        return $to === TicketStatus::Assigned
            || $to === TicketStatus::Open
            || ($from === TicketStatus::Open && $to === TicketStatus::InProgress)
            || ($from === TicketStatus::Open && $to === TicketStatus::Closed);
    }
}
