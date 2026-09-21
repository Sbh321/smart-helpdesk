<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * The experiment commands themselves (docs/05-algorithms/evaluation-methodology.md). These tests use
 * temporary folders only, so they run without the committed datasets.
 */

function commandTempFolder(): string
{
    return sys_get_temp_dir().'/experiment-cmd-'.bin2hex(random_bytes(6));
}

it('generates the same workload files for the same seed and never overwrites them', function (): void {
    [$first, $second] = [commandTempFolder(), commandTempFolder()];

    $this->artisan('experiment:generate-workload', ['--seed' => 42, '--output' => $first])->assertSuccessful();
    $this->artisan('experiment:generate-workload', ['--seed' => 42, '--output' => $second])->assertSuccessful();

    foreach (['agents.json', 'categories.json', 'tickets.csv'] as $file) {
        expect(file_get_contents("{$first}/{$file}"))->toBe(file_get_contents("{$second}/{$file}"));
    }
    expect(file("{$first}/tickets.csv"))->toHaveCount(501)
        ->and(file_get_contents("{$first}/README.md"))->toContain('--seed=42');

    $this->artisan('experiment:generate-workload', ['--seed' => 42, '--output' => $first])->assertFailed();
});

it('merges the hand-written pairs into 300 labelled pairs and writes the haystack', function (): void {
    $folder = commandTempFolder();
    mkdir("{$folder}/sources", 0775, true);
    file_put_contents("{$folder}/sources/duplicates-hand-written.json", json_encode(['pairs' => array_map(fn (int $i): array => [
        'category' => 'billing',
        'a' => ['title' => "Invoice problem {$i}", 'description' => 'Invoice total is wrong.'],
        'b' => ['title' => "Wrong invoice {$i}", 'description' => 'The invoice amount is wrong.'],
    ], range(1, 40))]));

    $this->artisan('experiment:generate-duplicates', ['--seed' => 42, '--output' => $folder])->assertSuccessful();

    $pairs = json_decode((string) file_get_contents("{$folder}/duplicates.json"), true);
    $haystack = json_decode((string) file_get_contents("{$folder}/duplicates-haystack.json"), true);
    expect($pairs['meta'])->toMatchArray(['seed' => 42, 'pairs' => 300, 'duplicates' => 100, 'non_duplicates' => 200])
        ->and($pairs['pairs'][60]['source'])->toBe('hand-written')
        ->and($haystack['meta']['seed'])->toBe(43)
        ->and($haystack['tickets'])->toHaveCount(5000);

    $this->artisan('experiment:generate-duplicates', ['--seed' => 42, '--output' => $folder])->assertFailed();
});

it('rejects an unknown experiment', function (): void {
    $this->artisan('experiment:run', ['experiment' => 'e9', '--output' => commandTempFolder()])->assertFailed();
});

it('resolves the strategy under test by name and rejects unknown names', function (): void {
    config(['helpdesk.experiments.path' => commandTempFolder()]);

    expect(fn () => $this->artisan('experiment:run', ['experiment' => 'e4', '--strategy' => 'nonexistent', '--output' => commandTempFolder()])->run())
        ->toThrow(LogicException::class, "No sla strategy named 'nonexistent'");
});

it('refuses to run E6 against a database that is not a test database', function (): void {
    config(['helpdesk.experiments.database' => 'helpdesk']);

    expect(fn () => $this->artisan('experiment:run', ['experiment' => 'e6', '--output' => commandTempFolder()])->run())
        ->toThrow(RuntimeException::class, 'only runs against a *_test database');
});

it('exposes no experiment over HTTP', function (): void {
    $uris = array_map(fn ($route): string => $route->uri(), Route::getRoutes()->getRoutes());

    expect(array_filter($uris, fn (string $uri): bool => str_contains($uri, 'experiment')))->toBe([]);
});
