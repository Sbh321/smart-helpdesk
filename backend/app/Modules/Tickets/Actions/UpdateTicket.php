<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Actions;

use App\Models\User;
use App\Modules\Tickets\Events\TicketPriorityInputsChanged;
use App\Modules\Tickets\Events\TicketUpdated;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketEvent;
use App\Support\Time\Clock;
use Illuminate\Support\Facades\DB;

final readonly class UpdateTicket
{
    public function __construct(private Clock $clock) {}

    /**
     * @param  array{title?: string, description?: string, category_id?: string, impact?: int, urgency?: int, tags?: list<string>}  $data
     */
    public function __invoke(Ticket $ticket, array $data, User $actor): Ticket
    {
        return DB::transaction(function () use ($ticket, $data, $actor): Ticket {
            $locked = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $oldValues = [];
            $newValues = [];

            foreach (['title', 'description', 'category_id', 'impact', 'urgency'] as $field) {
                if (array_key_exists($field, $data) && $locked->getAttribute($field) !== $data[$field]) {
                    $oldValues[$field] = $locked->getAttribute($field);
                    $newValues[$field] = $data[$field];
                    $locked->setAttribute($field, $data[$field]);
                }
            }

            if (array_key_exists('tags', $data)) {
                $oldTags = $locked->tags()->pluck('name')->all();
                $newTags = array_values(array_unique(array_filter(array_map('trim', $data['tags']), fn (string $tag): bool => $tag !== '')));

                if ($oldTags !== $newTags) {
                    $oldValues['tags'] = $oldTags;
                    $newValues['tags'] = $newTags;
                    $locked->syncTagNames($newTags);
                }
            }

            if ($newValues === []) {
                return $locked;
            }

            $now = $this->clock->now();
            $locked->version = (int) $locked->version + 1;
            $locked->updated_at = $now;
            $locked->save();

            TicketEvent::query()->create([
                'ticket_id' => $locked->id,
                'type' => 'edited',
                'actor_type' => 'user',
                'actor_id' => $actor->id,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'created_at' => $now,
            ]);

            event(new TicketUpdated($locked->tenant_id, $locked->id, $actor->id, $newValues));

            if (isset($newValues['impact']) || isset($newValues['urgency'])) {
                // Synchronous hook: Automation rescores inside this transaction.
                event(new TicketPriorityInputsChanged($locked->tenant_id, $locked->id, $actor->id, initial: false));
                $locked->refresh();
            }

            return $locked;
        });
    }
}
