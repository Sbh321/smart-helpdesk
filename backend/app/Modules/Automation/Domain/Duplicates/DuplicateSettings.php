<?php

declare(strict_types=1);

namespace App\Modules\Automation\Domain\Duplicates;

use App\Modules\Automation\Domain\Exceptions\InvalidStrategySettings;

/**
 * Tenant settings of the Jaccard baseline (`automation.duplicates.baseline`).
 * `candidateLimit` and `windowDays` are used by the candidate loader, not by the algorithm.
 */
final readonly class DuplicateSettings
{
    public function __construct(
        public float $threshold = 0.35,
        public int $maxSuggestions = 5,
        public int $candidateLimit = 50,
        public int $windowDays = 30,
    ) {
        if ($threshold <= 0 || $threshold > 1) {
            throw new InvalidStrategySettings('The duplicate threshold must be greater than 0 and at most 1.');
        }

        if ($maxSuggestions < 1 || $candidateLimit < 1 || $windowDays < 1) {
            throw new InvalidStrategySettings('Duplicate limits must be positive.');
        }
    }

    /**
     * @param  array{threshold?: int|float, max_suggestions?: int, candidate_limit?: int, window_days?: int}  $settings
     */
    public static function fromArray(array $settings): self
    {
        return new self(
            threshold: (float) ($settings['threshold'] ?? 0.35),
            maxSuggestions: (int) ($settings['max_suggestions'] ?? 5),
            candidateLimit: (int) ($settings['candidate_limit'] ?? 50),
            windowDays: (int) ($settings['window_days'] ?? 30),
        );
    }

    /**
     * @return array{threshold: float, max_suggestions: int, candidate_limit: int, window_days: int}
     */
    public function toArray(): array
    {
        return [
            'threshold' => $this->threshold,
            'max_suggestions' => $this->maxSuggestions,
            'candidate_limit' => $this->candidateLimit,
            'window_days' => $this->windowDays,
        ];
    }
}
