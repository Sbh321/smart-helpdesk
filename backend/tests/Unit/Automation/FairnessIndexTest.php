<?php

declare(strict_types=1);

use App\Modules\Automation\Domain\Assignment\FairnessIndex;
use App\Modules\Automation\Domain\Exceptions\InvalidStrategySettings;

it('computes Jain\'s fairness index', function (array $values, float $expected): void {
    expect(FairnessIndex::jain($values))->toEqualWithDelta($expected, 1e-9);
})->with([
    'equal loads' => [[3, 3, 3, 3], 1.0],
    'one agent holds everything' => [[8, 0, 0, 0], 0.25],
    'mixed' => [[1, 2, 3], 36 / 42],
    'fractions' => [[0.3, 0.4, 0.3], 1.0 / (3 * 0.34)],
    'empty' => [[], 1.0],
    'all zero' => [[0, 0], 1.0],
]);

it('computes the standard deviation and the coefficient of variation', function (): void {
    expect(FairnessIndex::standardDeviation([2, 4, 4, 4, 5, 5, 7, 9]))->toBe(2.0)
        ->and(FairnessIndex::coefficientOfVariation([2, 4, 4, 4, 5, 5, 7, 9]))->toBe(0.4)
        ->and(FairnessIndex::standardDeviation([]))->toBe(0.0)
        ->and(FairnessIndex::coefficientOfVariation([]))->toBe(0.0)
        ->and(FairnessIndex::coefficientOfVariation([0, 0]))->toBe(0.0)
        ->and(FairnessIndex::coefficientOfVariation([5, 5]))->toBe(0.0);
});

it('rejects negative loads', function (): void {
    expect(fn () => FairnessIndex::jain([1, -1]))->toThrow(InvalidStrategySettings::class)
        ->and(fn () => FairnessIndex::coefficientOfVariation([-2]))->toThrow(InvalidStrategySettings::class);
});
