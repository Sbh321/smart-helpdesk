<?php

declare(strict_types=1);

namespace App\Modules\Automation\Domain\Duplicates;

/**
 * Output of a duplicate strategy: the best matches at or above the threshold, best first.
 */
final readonly class DuplicateResult
{
    /**
     * @param  list<DuplicateMatch>  $matches
     * @param  list<string>  $words  the word set of the new ticket
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        public array $matches,
        public array $words,
        public int $candidatesCompared,
        public string $strategy,
        public string $strategyVersion,
        public array $settings = [],
    ) {}

    /**
     * @return array{strategy: string, strategy_version: string, words: list<string>, candidates_compared: int, settings: array<string, mixed>, matches: list<array{ticket_id: string, score: float, shared_words: list<string>, shared: int, union: int}>}
     */
    public function explanation(): array
    {
        return [
            'strategy' => $this->strategy,
            'strategy_version' => $this->strategyVersion,
            'words' => $this->words,
            'candidates_compared' => $this->candidatesCompared,
            'settings' => $this->settings,
            'matches' => array_map(static fn (DuplicateMatch $match): array => $match->toArray(), $this->matches),
        ];
    }
}
