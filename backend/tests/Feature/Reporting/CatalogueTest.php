<?php

declare(strict_types=1);

use App\Modules\Reporting\Reports\ReportCatalogue;
use App\Modules\Reporting\Reports\ReportDefinition;
use App\Modules\Reporting\Reports\ReportRunner;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;

require_once __DIR__.'/CatalogueHelpers.php';

// Every catalogued report (roadmap M3-02): runs with its defaults, fast, and with every dimension.

beforeEach(function (): void {
    $this->app->instance(Clock::class, new FrozenClock('2026-09-21 06:00:00'));
    $this->tenant = createTenant('catalogue-all', ['timezone' => 'Asia/Kathmandu']);
    catalogueWorkspace($this->tenant, 40, 11);
    actingAsRole($this->tenant, 'owner');
    tenancy()->initialize($this->tenant);
});

it('lists every report to the owner, in catalogue order, with unique keys', function (): void {
    $keys = array_column($this->getJson('/v1/reports')->assertOk()->json('data'), 'key');

    expect($keys)->toHaveCount(count(ReportCatalogue::REPORTS))
        ->and($keys)->toBe(array_values(array_unique($keys)))
        ->and($keys[0])->toBe('rpt-t01')
        ->and(end($keys))->toBe('rpt-g02');
});

it('runs every report with its default parameters in under a second', function (): void {
    foreach (app(ReportCatalogue::class)->all() as $report) {
        $started = hrtime(true);
        $data = $this->postJson("/v1/reports/{$report->key()}/run")->assertOk()->json('data');
        $seconds = (hrtime(true) - $started) / 1e9;

        expect($seconds)->toBeLessThan(1.0, "{$report->key()} took {$seconds} s")
            ->and(array_keys($data['totals']))->toBe(array_keys($report->measures()))
            ->and($data['rows'])->toBeArray();
        foreach ($data['rows'] as $row) {
            expect($row)->toHaveKeys(['key', 'label', 'values'])
                ->and(array_keys($row['values']))->toBe(array_keys($report->measures()));
        }
    }
});

it('runs every report with every dimension and a comparison', function (): void {
    $runner = app(ReportRunner::class);
    foreach (app(ReportCatalogue::class)->all() as $report) {
        foreach (array_keys($report->dimensions()) as $dimension) {
            $parameters = $runner->parameters($report, ['from' => '2026-08-25', 'to' => '2026-09-21', 'group' => $dimension, 'compare' => true], $this->tenant->id, 'Asia/Kathmandu');
            $result = $report->run($parameters);

            expect($result->rows)->toBeArray("{$report->key()} by {$dimension}");
            foreach ($result->rows as $row) {
                expect($row['label'])->toBeString();
            }
        }
    }
});

it('gives every report a description, a group and a known chart', function (): void {
    foreach (app(ReportCatalogue::class)->all() as $report) {
        /** @var ReportDefinition $report */
        expect($report->description())->not->toBe('')
            ->and($report->group())->toBeIn(['tickets', 'contacts', 'agents', 'sla', 'media', 'administration'])
            ->and($report->chart())->toBeIn(['bar', 'line', 'stacked_area', 'heatmap', 'histogram', 'table'])
            ->and($report->dimensions())->toHaveKey($report->defaultDimension());
    }
});
