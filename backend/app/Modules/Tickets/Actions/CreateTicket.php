<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Actions;

use App\Models\User;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Tickets\Domain\Priority;
use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketEvent;
use App\Support\Time\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Creates a ticket (docs/04-domain/tickets.md), version 0: number, `open` status and the
 * `created` history event, without automation.
 *
 * MVP-SHORTCUT: priority stays at the P4 default and nothing is assigned or checked for
 * duplicates; V1: none (M2-04 priority, M2-05 assignment, M2-10 duplicates plug in here).
 */
final readonly class CreateTicket
{
    public function __construct(
        private AllocateTicketNumber $allocateNumber,
        private Clock $clock,
    ) {}

    /**
     * @param  array{title: string, description: string, contact_id: string, category_id: string, impact: int, urgency: int, tags?: list<string>}  $data
     */
    public function __invoke(array $data, ?User $actor = null, string $via = 'ui'): Ticket
    {
        return DB::transaction(function () use ($data, $actor, $via): Ticket {
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
                'created_via' => $via,
            ]);
            $ticket->number = ($this->allocateNumber)($tenantId);
            $ticket->created_at = $now;
            $ticket->updated_at = $now;
            $ticket->save();

            if (($data['tags'] ?? []) !== []) {
                $ticket->syncTagNames($data['tags']);
            }

            $contact->forceFill(['last_ticket_at' => $now])->save();

            TicketEvent::query()->create([
                'ticket_id' => $ticket->id,
                'type' => 'created',
                'actor_type' => $actor === null ? 'system' : 'user',
                'actor_id' => $actor?->getKey(),
                'new_values' => [
                    'number' => $ticket->number,
                    'status' => TicketStatus::Open->value,
                    'impact' => $ticket->impact,
                    'urgency' => $ticket->urgency,
                    'category_id' => $ticket->category_id,
                    'created_via' => $via,
                ],
                'created_at' => $now,
            ]);

            return $ticket;
        });
    }
}
