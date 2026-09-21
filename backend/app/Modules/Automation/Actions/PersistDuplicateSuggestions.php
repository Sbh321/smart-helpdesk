<?php

declare(strict_types=1);

namespace App\Modules\Automation\Actions;

use App\Modules\Automation\Domain\Duplicates\DuplicateResult;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketDuplicateSuggestion;
use App\Modules\Tickets\Models\TicketEvent;
use App\Support\Time\Clock;

final class PersistDuplicateSuggestions
{
    public function __construct(private readonly Clock $clock) {}

    public function __invoke(Ticket $ticket, DuplicateResult $result): void
    {
        $created = [];
        foreach ($result->matches as $match) {
            $suggestion = TicketDuplicateSuggestion::query()->firstOrCreate(
                ['ticket_id' => $ticket->id, 'candidate_ticket_id' => $match->ticketId],
                [
                    'score' => round($match->score(), 4),
                    'breakdown' => [
                        'strategy' => $result->strategy,
                        'strategy_version' => $result->strategyVersion,
                        'shared_words' => $match->sharedWords,
                        'shared' => count($match->sharedWords),
                        'union' => $match->unionSize,
                        'candidates_compared' => $result->candidatesCompared,
                    ],
                    'decision' => 'pending',
                    'decided_by_user_id' => null,
                    'decided_at' => null,
                ],
            );
            if ($suggestion->wasRecentlyCreated) {
                $created[] = $suggestion->candidate_ticket_id;
            }
        }

        if ($created !== []) {
            TicketEvent::query()->create([
                'ticket_id' => $ticket->id,
                'type' => 'duplicate_suggested',
                'actor_type' => 'system',
                'actor_id' => null,
                'old_values' => [],
                'new_values' => ['candidate_ticket_ids' => $created, 'strategy' => $result->strategy],
                'created_at' => $this->clock->now(),
            ]);
        }
    }
}
