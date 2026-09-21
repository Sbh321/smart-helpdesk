<?php

declare(strict_types=1);

/*
 * Reproducibility of the pure experiments (docs/05-algorithms/evaluation-methodology.md §3.5): two runs
 * with the same seed write identical files. Needs the committed datasets in experiments/ next to backend/
 * (present in CI; in Docker run the tests with `-v ./experiments:/var/www/experiments`, see experiments/README.md).
 */

function experimentDatasets(): string
{
    return config('helpdesk.experiments.path').'/datasets/'.config('helpdesk.experiments.dataset');
}

function experimentTempFolder(): string
{
    $folder = sys_get_temp_dir().'/experiment-'.bin2hex(random_bytes(6));
    mkdir($folder, 0775, true);

    return $folder;
}

/**
 * Every file of a result folder; `run.json` without its `environment` block (commit, versions, time).
 *
 * @return array<string, string>
 */
function experimentFiles(string $folder): array
{
    $files = [];
    foreach (glob("{$folder}/*") ?: [] as $path) {
        $contents = (string) file_get_contents($path);
        if (basename($path) === 'run.json') {
            $run = json_decode($contents, true);
            unset($run['environment']);
            $contents = json_encode($run);
        }
        $files[basename($path)] = $contents;
    }
    ksort($files);

    return $files;
}

beforeEach(function (): void {
    if (! is_file(experimentDatasets().'/duplicates.json')) {
        $this->markTestSkipped('experiments/datasets is not available here; mount experiments/ (see experiments/README.md).');
    }
});

it('writes identical tables when run twice with the same seed', function (string $experiment): void {
    [$first, $second] = [experimentTempFolder(), experimentTempFolder()];

    $this->artisan('experiment:run', ['experiment' => $experiment, '--seed' => 42, '--output' => $first])->assertSuccessful();
    $this->artisan('experiment:run', ['experiment' => $experiment, '--seed' => 42, '--output' => $second])->assertSuccessful();

    $files = experimentFiles("{$first}/{$experiment}");
    expect($files)->toHaveKeys(['run.json', 'summary.json'])
        ->and(count($files))->toBeGreaterThan(2)
        ->and($files)->toBe(experimentFiles("{$second}/{$experiment}"));

    $run = json_decode($files['run.json'], true);
    expect($run)->toMatchArray(['experiment' => $experiment, 'seed' => 42, 'dataset' => 'v1', 'strategy_selected' => 'baseline'])
        ->and($run['strategy'])->toHaveKeys(['name', 'version', 'class']);
})->with(['e1', 'e2', 'e3', 'e4']);

it('changes the random comparison policy with the seed but not the baseline', function (): void {
    [$first, $second] = [experimentTempFolder(), experimentTempFolder()];

    $this->artisan('experiment:run', ['experiment' => 'e1', '--seed' => 42, '--output' => $first])->assertSuccessful();
    $this->artisan('experiment:run', ['experiment' => 'e1', '--seed' => 7, '--output' => $second])->assertSuccessful();

    $rows = fn (string $folder): array => array_map(str_getcsv(...), file("{$folder}/e1/t1-assignment-policies.csv", FILE_IGNORE_NEW_LINES) ?: []);
    [$a, $b] = [$rows($first), $rows($second)];

    expect($a[1][0])->toBe('random')->and($a[1])->not->toBe($b[1])
        ->and($a[3][0])->toBe('least_loaded_agent')->and($a[3])->toBe($b[3]);
});

it('matches every hand-written SLA and priority expectation with the baseline', function (): void {
    $folder = experimentTempFolder();
    $this->artisan('experiment:run', ['experiment' => 'e3', '--output' => $folder])->assertSuccessful();
    $this->artisan('experiment:run', ['experiment' => 'e4', '--output' => $folder])->assertSuccessful();

    expect(json_decode((string) file_get_contents("{$folder}/e3/summary.json"), true)['scenarios_matching'])->toBe(12)
        ->and(json_decode((string) file_get_contents("{$folder}/e4/summary.json"), true)['matching'])->toBe(10);
});
