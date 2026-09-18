<?php

declare(strict_types=1);

use App\Modules\Automation\Domain\Exceptions\InvalidStrategySettings;
use App\Modules\Automation\Domain\Priority\CustomerTier;
use App\Modules\Automation\Domain\Priority\PriorityInput;
use App\Modules\Automation\Domain\Priority\PriorityLevel;
use App\Modules\Automation\Domain\Priority\PrioritySettings;
use App\Modules\Automation\Strategies\Baseline\BasicWeightedPriority;
use App\Support\Attributes\AcademicBaseline;

// docs/05-algorithms/priority-scoring.md §Worked examples, row by row.
it('reproduces the worked example table', function (int $impact, int $urgency, CustomerTier $tier, float $hours, array $contributions, float $score, PriorityLevel $level): void {
    $result = (new BasicWeightedPriority)->score(new PriorityInput($impact, $urgency, $tier, $hours));

    expect($result->score)->toBe($score)
        ->and($result->level)->toBe($level)
        ->and(array_map(static fn ($part): float => round($part->contribution, 1), $result->parts))->toBe($contributions);
})->with([
    'payment system down for all users' => [4, 4, CustomerTier::Enterprise, 0.0, [40.0, 35.0, 15.0, 0.0], 90.0, PriorityLevel::P1],
    'report export slow for one department' => [3, 2, CustomerTier::Standard, 0.0, [26.7, 11.7, 0.0, 0.0], 38.3, PriorityLevel::P3],
    'team cannot upload files' => [2, 3, CustomerTier::Premium, 24.0, [13.3, 23.3, 7.5, 3.3], 47.5, PriorityLevel::P3],
    'same ticket after 72 h' => [2, 3, CustomerTier::Premium, 72.0, [13.3, 23.3, 7.5, 10.0], 54.2, PriorityLevel::P2],
    'typo on a help page' => [1, 1, CustomerTier::Standard, 0.0, [0.0, 0.0, 0.0, 0.0], 0.0, PriorityLevel::P4],
]);

it('scales every input to 0–1 at its bounds and saturates age at 72 hours', function (): void {
    $low = (new BasicWeightedPriority)->score(new PriorityInput(1, 1, CustomerTier::Standard, 0.0));
    $high = (new BasicWeightedPriority)->score(new PriorityInput(4, 4, CustomerTier::Enterprise, 10_000.0));

    expect(array_column(array_map(static fn ($p): array => $p->toArray(), $low->parts), 'value'))->toBe([0.0, 0.0, 0.0, 0.0])
        ->and(array_column(array_map(static fn ($p): array => $p->toArray(), $high->parts), 'value'))->toBe([1.0, 1.0, 1.0, 1.0])
        ->and($high->score)->toBe(100.0)
        ->and(CustomerTier::Premium->scaled())->toBe(0.5);
});

it('switches level exactly at the thresholds', function (float $threshold, PriorityLevel $atThreshold, PriorityLevel $below): void {
    // Only the age part counts (weight 1), so the score equals hours × 100 / 72 hours; use a 100-hour saturation for easy numbers.
    $strategy = new BasicWeightedPriority(new PrioritySettings(
        weights: ['impact' => 0.0, 'urgency' => 0.0, 'tier' => 0.0, 'age' => 1.0],
        ageFullHours: 100.0,
    ));

    expect($strategy->score(new PriorityInput(1, 1, CustomerTier::Standard, $threshold))->level)->toBe($atThreshold)
        ->and($strategy->score(new PriorityInput(1, 1, CustomerTier::Standard, $threshold - 0.1))->level)->toBe($below);
})->with([
    'P1 at 75' => [75.0, PriorityLevel::P1, PriorityLevel::P2],
    'P2 at 50' => [50.0, PriorityLevel::P2, PriorityLevel::P3],
    'P3 at 25' => [25.0, PriorityLevel::P3, PriorityLevel::P4],
]);

it('reaches exactly 75 from the default weights without float noise', function (): void {
    $result = (new BasicWeightedPriority)->score(new PriorityInput(4, 4, CustomerTier::Standard, 0.0));

    expect($result->score)->toBe(75.0)->and($result->level)->toBe(PriorityLevel::P1);
});

it('uses injected weights and thresholds and records them in the explanation', function (): void {
    $settings = PrioritySettings::fromArray([
        'weights' => ['impact' => 0.5, 'urgency' => 0.5, 'tier' => 0, 'age' => 0],
        'thresholds' => ['P1' => 90, 'P2' => 60, 'P3' => 30],
        'age_full_hours' => 24,
    ]);
    $result = (new BasicWeightedPriority($settings))->score(new PriorityInput(4, 3, CustomerTier::Enterprise, 48.0));
    $explanation = $result->explanation();

    expect($result->score)->toBe(83.3)
        ->and($result->level)->toBe(PriorityLevel::P2)
        ->and($explanation)->toMatchArray([
            'strategy' => 'basic_weighted_priority',
            'strategy_version' => '1.0.0',
            'score' => 83.3,
            'level' => 'P2',
            'effective_level' => 'P2',
            'manual_override' => false,
        ])
        ->and($explanation['settings'])->toBe([
            'weights' => ['impact' => 0.5, 'urgency' => 0.5, 'tier' => 0.0, 'age' => 0.0],
            'thresholds' => ['P1' => 90.0, 'P2' => 60.0, 'P3' => 30.0],
            'age_full_hours' => 24.0,
        ])
        ->and($explanation['parts'][0])->toBe(['name' => 'impact', 'value' => 1.0, 'weight' => 0.5, 'contribution' => 50.0]);
});

it('lets a manual priority win while keeping the computed score', function (): void {
    $result = (new BasicWeightedPriority)->score(new PriorityInput(4, 4, CustomerTier::Enterprise));

    expect($result->effectiveLevel(PriorityLevel::P4))->toBe(PriorityLevel::P4)
        ->and($result->explanation(PriorityLevel::P4))->toMatchArray(['level' => 'P1', 'effective_level' => 'P4', 'manual_override' => true, 'score' => 90.0]);
});

it('takes its defaults from fromArray with an empty array', function (): void {
    expect(PrioritySettings::fromArray([]))->toEqual(new PrioritySettings);
});

it('rejects invalid settings', function (array $settings, string $message): void {
    expect(fn () => PrioritySettings::fromArray($settings))->toThrow(InvalidStrategySettings::class, $message);
})->with([
    'weights not adding up to 1' => [['weights' => ['impact' => 0.5, 'urgency' => 0.35, 'tier' => 0.15, 'age' => 0.10]], 'add up to 1'],
    'negative weight' => [['weights' => ['impact' => 1.2, 'urgency' => -0.2, 'tier' => 0, 'age' => 0]], 'between 0 and 1'],
    'missing weight' => [['weights' => ['impact' => 0.5, 'urgency' => 0.5]], 'exactly the keys'],
    'unknown threshold' => [['thresholds' => ['P1' => 75, 'P2' => 50, 'P4' => 25]], 'exactly the keys'],
    'thresholds not decreasing' => [['thresholds' => ['P1' => 50, 'P2' => 50, 'P3' => 25]], 'strictly decreasing'],
    'threshold above 100' => [['thresholds' => ['P1' => 120, 'P2' => 50, 'P3' => 25]], 'strictly decreasing'],
    'zero P3 threshold' => [['thresholds' => ['P1' => 75, 'P2' => 50, 'P3' => 0]], 'strictly decreasing'],
    'zero age saturation' => [['age_full_hours' => 0], 'age_full_hours'],
]);

it('accepts weights within the 0.001 tolerance', function (): void {
    expect(PrioritySettings::fromArray(['weights' => ['impact' => 0.4, 'urgency' => 0.35, 'tier' => 0.15, 'age' => 0.1005]])->weights['age'])->toBe(0.1005);
});

it('rejects inputs outside their scales', function (int $impact, int $urgency, float $hours): void {
    expect(fn () => new PriorityInput($impact, $urgency, CustomerTier::Standard, $hours))->toThrow(InvalidStrategySettings::class);
})->with([
    'impact 0' => [0, 1, 0.0],
    'impact 5' => [5, 1, 0.0],
    'urgency 5' => [1, 5, 0.0],
    'negative age' => [1, 1, -1.0],
]);

it('is marked as an academic baseline', function (): void {
    $attributes = (new ReflectionClass(BasicWeightedPriority::class))->getAttributes(AcademicBaseline::class);

    expect($attributes)->toHaveCount(1)
        ->and((new ReflectionClass(BasicWeightedPriority::class))->getDocComment())->toContain('@deprecated')->toContain('0023');
});
