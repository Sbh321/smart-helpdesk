<?php

declare(strict_types=1);

use App\Modules\Reporting\Domain\Stats\Percentile;

it('reproduces the percentile worked example (odd count)', function (): void {
    $hours = [10, 1, 4, 2, 3];

    expect(Percentile::median($hours))->toBe(3.0)
        ->and(Percentile::p90($hours))->toEqualWithDelta(7.6, 1e-9)
        ->and(Percentile::mean($hours))->toBe(4.0);
});

it('interpolates between the middle values for an even count', function (): void {
    expect(Percentile::median([4, 1, 3, 2]))->toBe(2.5)
        ->and(Percentile::p90([4, 1, 3, 2]))->toEqualWithDelta(3.7, 1e-9)
        ->and(Percentile::continuous([4, 1, 3, 2], 0.0))->toBe(1.0)
        ->and(Percentile::continuous([4, 1, 3, 2], 1.0))->toBe(4.0);
});

it('returns null for no values and the value itself for one value', function (): void {
    expect(Percentile::median([]))->toBeNull()
        ->and(Percentile::p90([]))->toBeNull()
        ->and(Percentile::mean([]))->toBeNull()
        ->and(Percentile::median([7]))->toBe(7.0)
        ->and(Percentile::p90([7]))->toBe(7.0)
        ->and(Percentile::mean([7]))->toBe(7.0)
        ->and(Percentile::continuous([2.5, 2.5, 2.5], 0.37))->toBe(2.5);
});

it('rejects fractions outside 0..1', function (float $fraction): void {
    expect(fn () => Percentile::continuous([1, 2], $fraction))->toThrow(InvalidArgumentException::class, 'between 0 and 1');
})->with(['negative' => -0.1, 'above one' => 1.5]);

it('rejects non-finite values', function (): void {
    expect(fn () => Percentile::mean([1, INF]))->toThrow(InvalidArgumentException::class, 'finite')
        ->and(fn () => Percentile::median([NAN]))->toThrow(InvalidArgumentException::class, 'finite')
        ->and(fn () => Percentile::continuous([1, 2], NAN))->toThrow(InvalidArgumentException::class, 'between 0 and 1');
});
