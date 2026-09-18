<?php

declare(strict_types=1);

namespace App\Modules\Automation\Domain\Priority;

/**
 * Output of a priority strategy. `score` is rounded to one decimal; `level` is derived from that rounded score.
 */
final readonly class PriorityResult
{
    /**
     * @param  list<PriorityPart>  $parts
     * @param  array<string, mixed>  $settings  the settings used, stored with the explanation
     */
    public function __construct(
        public float $score,
        public PriorityLevel $level,
        public array $parts,
        public string $strategy,
        public string $strategyVersion,
        public array $settings = [],
    ) {}

    /**
     * A manual priority chosen by a manager always wins over the computed level; the score is still shown.
     */
    public function effectiveLevel(?PriorityLevel $manual): PriorityLevel
    {
        return $manual ?? $this->level;
    }

    /**
     * @return array{strategy: string, strategy_version: string, score: float, level: string, effective_level: string, manual_override: bool, parts: list<array{name: string, value: float, weight: float, contribution: float}>, settings: array<string, mixed>}
     */
    public function explanation(?PriorityLevel $manual = null): array
    {
        return [
            'strategy' => $this->strategy,
            'strategy_version' => $this->strategyVersion,
            'score' => $this->score,
            'level' => $this->level->value,
            'effective_level' => $this->effectiveLevel($manual)->value,
            'manual_override' => $manual !== null,
            'parts' => array_map(static fn (PriorityPart $part): array => $part->toArray(), $this->parts),
            'settings' => $this->settings,
        ];
    }
}
