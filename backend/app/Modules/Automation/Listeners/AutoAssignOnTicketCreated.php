<?php

declare(strict_types=1);

namespace App\Modules\Automation\Listeners;

use App\Modules\Automation\Actions\AssignTicket;
use App\Modules\Tenancy\Settings\Settings;
use App\Modules\Tickets\Events\TicketCreated;
use App\Modules\Tickets\Models\Ticket;

/**
 * Automatic assignment right after creation, in the same transaction. When nobody is eligible
 * the ticket stays open and unassigned; that outcome is recorded by AssignTicket, never thrown.
 */
final readonly class AutoAssignOnTicketCreated
{
    public function __construct(private AssignTicket $assign, private Settings $settings) {}

    public function handle(TicketCreated $event): void
    {
        if (! $this->settings->get('automation.assignment.enabled', true)) {
            return;
        }

        ($this->assign)(Ticket::query()->findOrFail($event->ticketId));
    }
}
