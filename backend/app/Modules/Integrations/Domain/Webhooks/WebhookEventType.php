<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Domain\Webhooks;

/**
 * The v1 event catalogue (docs/07-api/webhooks.md §Event catalogue). `ping` is the test event:
 * it is sent by `POST /v1/webhooks/{webhook}/test` and cannot be subscribed to.
 */
enum WebhookEventType: string
{
    case TicketCreated = 'ticket.created';
    case TicketUpdated = 'ticket.updated';
    case TicketAssigned = 'ticket.assigned';
    case TicketStatusChanged = 'ticket.status_changed';
    case TicketPriorityChanged = 'ticket.priority_changed';
    case TicketResolved = 'ticket.resolved';
    case TicketClosed = 'ticket.closed';
    case TicketCommentAdded = 'ticket.comment_added';
    case TicketSlaBreached = 'ticket.sla_breached';
    case ContactCreated = 'contact.created';
    case ContactUpdated = 'contact.updated';
    case Ping = 'ping';

    /**
     * @return list<self>
     */
    public static function subscribable(): array
    {
        $types = [];
        foreach (self::cases() as $type) {
            if ($type !== self::Ping) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * @return list<string>
     */
    public static function subscribableValues(): array
    {
        return array_map(fn (self $type): string => $type->value, self::subscribable());
    }

    public function description(): string
    {
        return match ($this) {
            self::TicketCreated => 'A ticket was created',
            self::TicketUpdated => 'Ticket fields were edited',
            self::TicketAssigned => 'A ticket was assigned or reassigned',
            self::TicketStatusChanged => 'A ticket changed status',
            self::TicketPriorityChanged => 'A ticket\'s priority level changed',
            self::TicketResolved => 'A ticket was resolved',
            self::TicketClosed => 'A ticket was closed',
            self::TicketCommentAdded => 'A public reply was added to a ticket',
            self::TicketSlaBreached => 'An SLA timer of a ticket was breached',
            self::ContactCreated => 'A contact was created',
            self::ContactUpdated => 'A contact was updated',
            self::Ping => 'Test delivery',
        };
    }
}
