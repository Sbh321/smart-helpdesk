<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Actions;

use App\Models\User;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Media\Actions\AttachMedia;
use App\Modules\Media\Models\MediaItem;
use App\Modules\Tickets\Domain\Priority;
use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Events\TicketCreated;
use App\Modules\Tickets\Events\TicketPriorityInputsChanged;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketEvent;
use App\Support\Auth\ApiClientPrincipal;
use App\Support\Time\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Creates a ticket (docs/04-domain/tickets.md): number, open status and the `created` history
 * entry. Priority, SLA timers, duplicate suggestions and automatic assignment are done by
 * listeners of two synchronous hook events fired inside this transaction, so Tickets does not
 * depend on Automation or Sla (docs/03-architecture/backend.md §Dependency rules).
 */
final readonly class CreateTicket
{
    public function __construct(
        private AllocateTicketNumber $allocateNumber,
        private AttachMedia $attachMedia,
        private Clock $clock,
    ) {}

    /**
     * @param  array{title: string, description: string, contact_id: string, category_id: string, impact: int, urgency: int, tags?: list<string>, attachment_ids?: list<string>}  $data
     * @param  string|null  $clientId  the API client that created the ticket (`$via` is then `api`)
     */
    public function __invoke(array $data, ?User $actor = null, string $via = 'ui', ?string $clientId = null): Ticket
    {
        return DB::transaction(function () use ($data, $actor, $via, $clientId): Ticket {
            $tenantId = (string) tenant()?->getTenantKey();
            $contact = Contact::query()->findOrFail($data['contact_id']);
            $now = $this->clock->now();

            $ticket = new Ticket([
                'title' => $data['title'],
                'description' => $data['description'],
                'contact_id' => $contact->id,
                'organization_id' => $contact->organization_id,
                'category_id' => $data['category_id'],
                'impact' => $data['impact'],
                'urgency' => $data['urgency'],
                'status' => TicketStatus::Open,
                'priority_level' => Priority::P4,
                'priority_score' => 0,
                'created_by_user_id' => $actor?->getKey(),
                'created_by_client_id' => $clientId,
                'created_via' => $via,
            ]);
            $ticket->number = ($this->allocateNumber)($tenantId);
            $ticket->created_at = $now;
            $ticket->updated_at = $now;
            $ticket->save();

            // Hook 1: Automation scores the ticket. It runs before the history entry and before the
            // SLA timers start, because both record the effective priority.
            event(new TicketPriorityInputsChanged($tenantId, $ticket->id, $actor?->getKey(), initial: true));
            $ticket->refresh();

            if (($data['tags'] ?? []) !== []) {
                $ticket->syncTagNames($data['tags']);
            }

            foreach ($data['attachment_ids'] ?? [] as $mediaId) {
                ($this->attachMedia)(MediaItem::query()->findOrFail($mediaId), 'ticket', $ticket->id);
            }

            $contact->forceFill(['last_ticket_at' => $now])->save();

            TicketEvent::query()->create([
                'ticket_id' => $ticket->id,
                'type' => 'created',
                'actor_type' => match (true) {
                    $actor !== null => 'user',
                    $clientId !== null => ApiClientPrincipal::TICKET_ACTOR_TYPE,
                    default => 'system',
                },
                'actor_id' => $actor?->getKey() ?? $clientId,
                'new_values' => [
                    'number' => $ticket->number,
                    'status' => TicketStatus::Open->value,
                    'impact' => $ticket->impact,
                    'urgency' => $ticket->urgency,
                    'category_id' => $ticket->category_id,
                    'priority' => $ticket->effectivePriority()->value,
                    'created_via' => $via,
                ],
                'created_at' => $now,
            ]);

            // Hook 2: Sla starts the timers; Automation records duplicate suggestions and, when
            // enabled, assigns the ticket. All of it commits or rolls back with the ticket.
            event(new TicketCreated($tenantId, $ticket->id));

            return $ticket->refresh();
        });
    }
}
