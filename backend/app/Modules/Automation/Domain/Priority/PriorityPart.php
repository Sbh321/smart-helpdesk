<?php

declare(strict_types=1);

namespace App\Modules\Automation\Domain\Priority;

/**
 * One term of the weighted sum: scaled value (0–1), weight and contribution in score points.
 */
final readonly class PriorityPart
{
    public function __construct(
        public string $name,
        public float $value,
        public float $weight,
        public float $contribution,
    ) {}

    /**
     * @return array{name: string, value: float, weight: float, contribution: float}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'value' => round($this->value, 4),
            'weight' => $this->weight,
            'contribution' => round($this->contribution, 4),
        ];
    }
}
