<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Listeners;

use App\Modules\Contacts\Events\ContactSaved;
use App\Modules\Integrations\Actions\QueueWebhookEvent;
use App\Modules\Integrations\Domain\Webhooks\WebhookEventType;
use App\Modules\Integrations\Webhooks\WebhookPayloads;
use App\Modules\Sla\Events\SlaBreached;
use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Events\CommentAdded;
use App\Modules\Tickets\Events\PriorityChanged;
use App\Modules\Tickets\Events\TicketAssigned;
use App\Modules\Tickets\Events\TicketCreated;
use App\Modules\Tickets\Events\TicketStatusChanged;
use App\Modules\Tickets\Events\TicketUpdated;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketAssignment;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Maps domain events to the webhook catalogue (docs/07-api/webhooks.md §Event catalogue).
 *
 * The events are dispatched after commit inside the workspace, so every query here is tenant-scoped
 * and the payload shows the state the event describes. The listener runs in the request (or job)
 * that raised the event; only the HTTP delivery is queued. A failure here is reported and never
 * reaches the user: the domain change has already committed.
 *
 * | Domain event | Webhook event(s) |
 * |---|---|
 * | `TicketCreated` (hook, deferred to after commit) | `ticket.created` |
 * | `TicketUpdated` | `ticket.updated` |
 * | `TicketAssigned` | `ticket.assigned` |
 * | `TicketStatusChanged` | `ticket.status_changed`, plus `ticket.resolved` / `ticket.closed` |
 * | `PriorityChanged` | `ticket.priority_changed` |
 * | `CommentAdded` (public only) | `ticket.comment_added` |
 * | `SlaBreached` | `ticket.sla_breached` |
 * | `ContactSaved` | `contact.created` / `contact.updated` |
 */
final readonly class DispatchWebhookEvent
{
    public function __construct(private QueueWebhookEvent $queue, private WebhookPayloads $payloads) {}

    public function ticketCreated(TicketCreated $event): void
    {
        // TicketCreated is a synchronous hook inside the creation transaction.
        DB::afterCommit(fn () => $this->emit(WebhookEventType::TicketCreated, fn (): ?array => $this->withTicket($event->ticketId, [])));
    }

    public function ticketUpdated(TicketUpdated $event): void
    {
        $this->emit(WebhookEventType::TicketUpdated, fn (): ?array => $this->withTicket($event->ticketId, ['changes' => $event->changes]));
    }

    public function ticketAssigned(TicketAssigned $event): void
    {
        $this->emit(WebhookEventType::TicketAssigned, function () use ($event): ?array {
            $assignment = TicketAssignment::query()->where('ticket_id', $event->ticketId)
                ->orderByDesc('created_at')->orderByDesc('id')->first();

            return $this->withTicket($event->ticketId, ['assignment' => [
                'agent_id' => $assignment?->getAttribute('agent_profile_id') ?? $event->agentId,
                'team_id' => $assignment?->getAttribute('team_id'),
                'reason' => $assignment?->reason,
            ]]);
        });
    }

    public function statusChanged(TicketStatusChanged $event): void
    {
        $this->emit(WebhookEventType::TicketStatusChanged, fn (): ?array => $this->withTicket($event->ticketId, ['from' => $event->from, 'to' => $event->to]));

        $specific = match ($event->to) {
            TicketStatus::Resolved->value => WebhookEventType::TicketResolved,
            TicketStatus::Closed->value => WebhookEventType::TicketClosed,
            default => null,
        };
        if ($specific !== null) {
            $this->emit($specific, fn (): ?array => $this->withTicket($event->ticketId, []));
        }
    }

    public function priorityChanged(PriorityChanged $event): void
    {
        $this->emit(WebhookEventType::TicketPriorityChanged, function () use ($event): ?array {
            $ticket = Ticket::query()->find($event->ticketId);
            $reason = $ticket?->priority_override_level !== null ? 'override' : 'automatic';

            return $this->withTicket($event->ticketId, ['from' => $event->from, 'to' => $event->to, 'reason' => $reason]);
        });
    }

    public function commentAdded(CommentAdded $event): void
    {
        // Internal notes are never delivered.
        if ($event->visibility !== 'public') {
            return;
        }

        $this->emit(WebhookEventType::TicketCommentAdded, function () use ($event): ?array {
            $comment = $this->payloads->comment($event->commentId);

            return $comment === null ? null : ['comment' => $comment, 'ticket_id' => $event->ticketId];
        });
    }

    public function slaBreached(SlaBreached $event): void
    {
        $this->emit(WebhookEventType::TicketSlaBreached, function () use ($event): ?array {
            $timer = $this->payloads->timer($event->timerId);

            return $timer === null ? null : $this->withTicket($event->ticketId, ['timer' => $timer]);
        });
    }

    public function contactSaved(ContactSaved $event): void
    {
        $this->emit(
            $event->created ? WebhookEventType::ContactCreated : WebhookEventType::ContactUpdated,
            function () use ($event): ?array {
                $contact = $this->payloads->contact($event->contactId);

                return $contact === null ? null : ['contact' => $contact];
            },
        );
    }

    /**
     * @param  Closure(): (array<string, mixed>|null)  $data
     */
    private function emit(WebhookEventType $type, Closure $data): void
    {
        try {
            ($this->queue)($type, $data);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>|null
     */
    private function withTicket(string $ticketId, array $extra): ?array
    {
        $ticket = $this->payloads->ticket($ticketId);

        return $ticket === null ? null : ['ticket' => $ticket, ...$extra];
    }
}
