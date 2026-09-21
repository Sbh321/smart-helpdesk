<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Http\Resources;

use App\Modules\Tickets\Models\TicketDuplicateSuggestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A stored duplicate suggestion for a ticket and the decision taken on it.
 *
 * @mixin TicketDuplicateSuggestion
 */
final class DuplicateSuggestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ticket_id' => $this->ticket_id,
            'candidate_ticket_id' => $this->candidate_ticket_id,
            'candidate' => [
                'number' => $this->candidate->number,
                'title' => $this->candidate->title,
                'status' => $this->candidate->status,
            ],
            'score' => (float) $this->score,
            /**
             * @var list<string>
             *
             * Words both tickets share, as the strategy counted them.
             */
            'shared_words' => $this->sharedWords(),
            /**
             * @var string
             *
             * Strategy that produced the suggestion; `manual` for a duplicate marked by hand.
             *
             * @example jaccard_duplicates
             */
            'strategy' => $this->breakdown['strategy'] ?? 'manual',
            /** @var string|null */
            'strategy_version' => $this->breakdown['strategy_version'] ?? null,
            'decision' => $this->decision,
            'decided_at' => $this->decided_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }

    /**
     * Typed for the API document: the breakdown column itself is free-form JSON.
     *
     * @return list<string>
     */
    private function sharedWords(): array
    {
        $words = $this->breakdown['shared_words'] ?? [];

        return is_array($words) ? array_values(array_map('strval', $words)) : [];
    }
}
