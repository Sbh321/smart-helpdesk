<?php

declare(strict_types=1);

use App\Support\Experiments\ExperimentOutput;
use App\Support\Experiments\Metrics;
use App\Support\Experiments\SeededRandom;

it('repeats the same random sequence for the same seed', function (): void {
    $draw = function (int $seed): array {
        $random = new SeededRandom($seed);

        return [$random->int(1, 1000), $random->float(), $random->exponential(10), $random->pick(['a', 'b', 'c']), $random->shuffle([1, 2, 3, 4, 5]), $random->weighted(['x' => 1, 'y' => 3])];
    };

    expect($draw(42))->toBe($draw(42))
        ->and($draw(42))->not->toBe($draw(43));
});

it('keeps random values within their bounds', function (): void {
    $random = new SeededRandom(7);
    $values = array_map(fn (): int => $random->int(5, 12), range(1, 500));
    $floats = array_map(fn (): float => $random->float(), range(1, 500));

    expect(min($values))->toBe(5)->and(max($values))->toBe(12)
        ->and(min($floats))->toBeGreaterThanOrEqual(0.0)->and(max($floats))->toBeLessThan(1.0)
        ->and($random->shuffle([3, 1, 2]))->toEqualCanonicalizing([1, 2, 3]);
});

it('draws exponential values with the requested mean', function (): void {
    $random = new SeededRandom(42);
    $values = array_map(fn (): float => $random->exponential(120), range(1, 20000));

    expect(Metrics::mean($values))->toEqualWithDelta(120, 4)
        ->and(min($values))->toBeGreaterThanOrEqual(0.0);
});

it('never picks a key with zero weight', function (): void {
    $random = new SeededRandom(1);
    $picks = array_map(fn (): string => $random->weighted(['never' => 0, 'always' => 1]), range(1, 200));

    expect(array_unique($picks))->toBe(['always']);
});

it('computes precision, recall and F1 with empty classes as zero', function (): void {
    expect(Metrics::precision(8, 2))->toBe(0.8)
        ->and(Metrics::recall(8, 8))->toBe(0.5)
        ->and(Metrics::f1(0.8, 0.5))->toEqualWithDelta(2 * 0.8 * 0.5 / 1.3, 1e-12)
        ->and(Metrics::precision(0, 0))->toBe(0.0)
        ->and(Metrics::recall(0, 0))->toBe(0.0)
        ->and(Metrics::f1(0.0, 0.0))->toBe(0.0)
        ->and(Metrics::mean([]))->toBe(0.0)
        ->and(Metrics::mean([1, 2, 6]))->toBe(3.0)
        ->and(Metrics::ratio(1, 0))->toBe(0.0)
        ->and(Metrics::round(0.123456))->toBe(0.1235)
        ->and((string) Metrics::round(-0.00001))->toBe('0');
});

it('writes CSV and JSON files and lists them', function (): void {
    $folder = sys_get_temp_dir().'/experiment-output-'.bin2hex(random_bytes(4));
    $out = new ExperimentOutput($folder.'/nested');
    $out->csv('table.csv', [['name' => 'a, "quoted"', 'value' => 0.5, 'flag' => true, 'empty' => null], ['name' => 'b', 'value' => 2, 'flag' => false, 'empty' => null]]);
    $out->json('data.json', ['score' => 1.0, 'path' => 'a/b']);

    expect(file_get_contents("{$folder}/nested/table.csv"))->toBe("name,value,flag,empty\n\"a, \"\"quoted\"\"\",0.5,true,\nb,2,false,\n")
        ->and(file_get_contents("{$folder}/nested/data.json"))->toBe("{\n    \"score\": 1.0,\n    \"path\": \"a/b\"\n}\n")
        ->and($out->files())->toBe(['table.csv', 'data.json']);
});
