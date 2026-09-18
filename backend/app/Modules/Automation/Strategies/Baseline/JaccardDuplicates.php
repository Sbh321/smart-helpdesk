<?php

declare(strict_types=1);

namespace App\Modules\Automation\Strategies\Baseline;

use App\Modules\Automation\Contracts\DuplicateStrategy;
use App\Modules\Automation\Domain\Duplicates\DuplicateMatch;
use App\Modules\Automation\Domain\Duplicates\DuplicateResult;
use App\Modules\Automation\Domain\Duplicates\DuplicateSettings;
use App\Modules\Automation\Domain\Duplicates\TicketText;
use App\Modules\Automation\Domain\Text\WordSet;
use App\Support\Attributes\AcademicBaseline;

/**
 * Jaccard Duplicate Check: J(A, B) = |A ∩ B| ÷ |A ∪ B| over the word sets of title and description;
 * suggest candidates with J ≥ threshold, best five first (docs/05-algorithms/duplicate-detection.md).
 *
 * @deprecated Academic baseline for the CACS452 defence; replace after the defence (docs/adr/0023-minimal-replaceable-algorithms.md).
 */
#[AcademicBaseline]
final readonly class JaccardDuplicates implements DuplicateStrategy
{
    public const string NAME = 'jaccard_duplicates';

    public const string VERSION = '1.0.0';

    private WordSet $wordSet;

    public function __construct(
        private DuplicateSettings $settings = new DuplicateSettings,
        ?WordSet $wordSet = null,
    ) {
        $this->wordSet = $wordSet ?? WordSet::fromFile();
    }

    public function find(TicketText $ticket, array $candidates): DuplicateResult
    {
        $words = $this->wordSet->words($ticket->title, $ticket->description);
        $matches = [];
        $compared = 0;

        foreach ($candidates as $candidate) {
            if ($candidate->id === $ticket->id) {
                continue;
            }

            $compared++;
            $match = $this->compare($words, $candidate);

            if ($match->score() >= $this->settings->threshold) {
                $matches[] = $match;
            }
        }

        usort($matches, $this->order(...));

        return new DuplicateResult(
            matches: array_slice($matches, 0, $this->settings->maxSuggestions),
            words: $words,
            candidatesCompared: $compared,
            strategy: self::NAME,
            strategyVersion: self::VERSION,
            settings: $this->settings->toArray(),
        );
    }

    /**
     * @param  list<string>  $words
     */
    private function compare(array $words, TicketText $candidate): DuplicateMatch
    {
        $other = $this->wordSet->words($candidate->title, $candidate->description);
        $shared = array_values(array_intersect($words, $other));
        sort($shared);
        $union = count($words) + count($other) - count($shared);

        return new DuplicateMatch($candidate->id, $shared, $union, $candidate->createdAt);
    }

    /**
     * Highest score first, then the newest ticket, then the smallest id.
     */
    private function order(DuplicateMatch $a, DuplicateMatch $b): int
    {
        return $b->compareScore($a)
            ?: (($b->createdAt?->getTimestamp() ?? PHP_INT_MIN) <=> ($a->createdAt?->getTimestamp() ?? PHP_INT_MIN))
            ?: strcmp($a->ticketId, $b->ticketId);
    }
}
