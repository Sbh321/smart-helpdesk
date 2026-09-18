<?php

declare(strict_types=1);

namespace App\Modules\Automation\Domain\Duplicates;

use DateTimeInterface;

/**
 * One suggested duplicate: Jaccard score = shared ÷ union words.
 */
final readonly class DuplicateMatch
{
    /**
     * @param  list<string>  $sharedWords  sorted alphabetically
     */
    public function __construct(
        public string $ticketId,
        public array $sharedWords,
        public int $unionSize,
        public ?DateTimeInterface $createdAt = null,
    ) {}

    public function score(): float
    {
        return $this->unionSize === 0 ? 0.0 : count($this->sharedWords) / $this->unionSize;
    }

    /**
     * Exact comparison of two scores (cross-multiplication); positive when this score is higher.
     */
    public function compareScore(self $other): int
    {
        return (count($this->sharedWords) * $other->unionSize) <=> (count($other->sharedWords) * $this->unionSize);
    }

    /**
     * @return array{ticket_id: string, score: float, shared_words: list<string>, shared: int, union: int}
     */
    public function toArray(): array
    {
        return [
            'ticket_id' => $this->ticketId,
            'score' => round($this->score(), 4),
            'shared_words' => $this->sharedWords,
            'shared' => count($this->sharedWords),
            'union' => $this->unionSize,
        ];
    }
}
