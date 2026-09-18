<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Domain;

use App\Modules\Tickets\Domain\Exceptions\InvalidTransition;

/**
 * Ticket lifecycle (docs/04-domain/tickets.md §State machine). The transition table lives here and
 * nowhere else; every status change goes through `transitionTo()`.
 */
enum TicketStatus: string
{
    case Open = 'open';
    case Assigned = 'assigned';
    case InProgress = 'in_progress';
    case Pending = 'pending';
    case Resolved = 'resolved';
    case Closed = 'closed';

    /**
     * @return list<self>
     */
    public function allowedTargets(): array
    {
        return match ($this) {
            self::Open => [self::Assigned, self::InProgress, self::Closed],
            self::Assigned => [self::InProgress, self::Pending, self::Resolved, self::Open],
            self::InProgress => [self::Pending, self::Resolved, self::Open],
            self::Pending => [self::InProgress, self::Resolved],
            self::Resolved => [self::Closed, self::InProgress],
            self::Closed => [self::InProgress],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTargets(), true);
    }

    /**
     * @throws InvalidTransition when the table has no such edge
     */
    public function transitionTo(self $target): self
    {
        if (! $this->canTransitionTo($target)) {
            throw InvalidTransition::between($this, $target);
        }

        return $target;
    }

    /**
     * Resolved and closed tickets are finished; everything else is in the active queue.
     */
    public function isActive(): bool
    {
        return ! in_array($this, [self::Resolved, self::Closed], true);
    }

    /**
     * Statuses that count as "active" in the `filter[status]=active` alias.
     *
     * @return list<self>
     */
    public static function active(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status): bool => $status->isActive()));
    }
}
