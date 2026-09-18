<?php

declare(strict_types=1);

namespace App\Modules\Automation\Domain\Priority;

use App\Modules\Automation\Domain\Exceptions\InvalidStrategySettings;

/**
 * Tenant settings of the Basic Weighted Priority baseline (`automation.priority.baseline`).
 */
final readonly class PrioritySettings
{
    public const array PARTS = ['impact', 'urgency', 'tier', 'age'];

    public const array LEVELS = ['P1', 'P2', 'P3'];

    private const float WEIGHT_TOLERANCE = 0.001;

    /**
     * @param  array<string, float>  $weights  impact, urgency, tier, age; must add up to 1
     * @param  array<string, float>  $thresholds  P1, P2, P3 lower bounds; strictly decreasing, within 0–100
     */
    public function __construct(
        public array $weights = ['impact' => 0.40, 'urgency' => 0.35, 'tier' => 0.15, 'age' => 0.10],
        public array $thresholds = ['P1' => 75.0, 'P2' => 50.0, 'P3' => 25.0],
        public float $ageFullHours = 72.0,
    ) {
        self::assertKeys($weights, self::PARTS, 'weights');
        self::assertKeys($thresholds, self::LEVELS, 'thresholds');

        foreach ($weights as $name => $weight) {
            if ($weight < 0 || $weight > 1) {
                throw new InvalidStrategySettings("Weight {$name} must be between 0 and 1.");
            }
        }

        if (abs(array_sum($weights) - 1.0) > self::WEIGHT_TOLERANCE) {
            throw new InvalidStrategySettings('Priority weights must add up to 1.');
        }

        if (! ($thresholds['P1'] <= 100 && $thresholds['P1'] > $thresholds['P2']
            && $thresholds['P2'] > $thresholds['P3'] && $thresholds['P3'] > 0)) {
            throw new InvalidStrategySettings('Priority thresholds must be strictly decreasing from P1 to P3 and lie within 0–100.');
        }

        if ($ageFullHours <= 0) {
            throw new InvalidStrategySettings('age_full_hours must be positive.');
        }
    }

    /**
     * Builds settings from the `automation.priority.baseline` array; missing keys take the defaults.
     *
     * @param  array{weights?: array<string, int|float>, thresholds?: array<string, int|float>, age_full_hours?: int|float}  $settings
     */
    public static function fromArray(array $settings): self
    {
        $defaults = new self;

        return new self(
            weights: array_map(floatval(...), $settings['weights'] ?? $defaults->weights),
            thresholds: array_map(floatval(...), $settings['thresholds'] ?? $defaults->thresholds),
            ageFullHours: (float) ($settings['age_full_hours'] ?? $defaults->ageFullHours),
        );
    }

    public function levelFor(float $score): PriorityLevel
    {
        return match (true) {
            $score >= $this->thresholds['P1'] => PriorityLevel::P1,
            $score >= $this->thresholds['P2'] => PriorityLevel::P2,
            $score >= $this->thresholds['P3'] => PriorityLevel::P3,
            default => PriorityLevel::P4,
        };
    }

    /**
     * @return array{weights: array<string, float>, thresholds: array<string, float>, age_full_hours: float}
     */
    public function toArray(): array
    {
        return ['weights' => $this->weights, 'thresholds' => $this->thresholds, 'age_full_hours' => $this->ageFullHours];
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  list<string>  $expected
     */
    private static function assertKeys(array $values, array $expected, string $label): void
    {
        $keys = array_keys($values);
        sort($keys);
        $sorted = $expected;
        sort($sorted);

        if ($keys !== $sorted) {
            throw new InvalidStrategySettings("Priority {$label} must have exactly the keys ".implode(', ', $expected).'.');
        }
    }
}
