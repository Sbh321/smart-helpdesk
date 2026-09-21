<?php

declare(strict_types=1);

use App\Modules\Automation\Console\Experiments\AssignmentReplay;
use App\Modules\Automation\Console\Experiments\Datasets\Workload;
use App\Modules\Automation\Console\Experiments\DuplicateExperiment;
use App\Modules\Automation\Console\Experiments\LoadMeasures;
use App\Modules\Automation\Console\Experiments\Policies\RoundRobinPolicy;
use App\Modules\Automation\Console\Experiments\PriorityExperiment;
use App\Modules\Automation\Strategies\Baseline\LeastLoadedAgent;

it('measures the load spread of one instant', function (): void {
    $measures = LoadMeasures::of(['a' => 2, 'b' => 4, 'c' => 0], ['a' => 4, 'b' => 4, 'c' => 8]);

    expect($measures['std_open'])->toEqualWithDelta(sqrt(8 / 3), 1e-12)
        ->and($measures['max_open'])->toBe(4)
        ->and($measures['min_open'])->toBe(0)
        ->and($measures['jain_open'])->toEqualWithDelta(36 / (3 * 20), 1e-12)
        ->and($measures['jain_utilisation'])->toEqualWithDelta(1.5 ** 2 / (3 * 1.25), 1e-12)
        ->and($measures['max_utilisation'])->toBe(1.0)
        ->and(LoadMeasures::of([], []))->toMatchArray(['std_open' => 0.0, 'max_open' => 0, 'jain_open' => 1.0]);
});

it('counts true and false positives and negatives at a threshold', function (): void {
    $pairs = [['id' => 'p1', 'label' => 'duplicate'], ['id' => 'p2', 'label' => 'duplicate'], ['id' => 'p3', 'label' => 'non_duplicate'], ['id' => 'p4', 'label' => 'non_duplicate']];
    $scores = ['p1' => 0.5, 'p2' => 0.2, 'p3' => 0.35, 'p4' => 0.1];

    expect(DuplicateExperiment::confusionOf($pairs, $scores, 0.35))->toBe(['tp' => 1, 'fp' => 1, 'fn' => 1, 'tn' => 1, 'precision' => 0.5, 'recall' => 0.5, 'f1' => 0.5])
        ->and(DuplicateExperiment::confusionOf($pairs, $scores, 0.9))->toMatchArray(['tp' => 0, 'fp' => 0, 'fn' => 2, 'tn' => 2, 'precision' => 0.0, 'f1' => 0.0]);
});

it('sweeps thresholds from 0.10 to 0.90 in steps of 0.05', function (): void {
    $thresholds = DuplicateExperiment::thresholds();

    expect($thresholds)->toHaveCount(17)
        ->and($thresholds[0])->toBe(0.1)
        ->and($thresholds[5])->toBe(0.35)
        ->and(end($thresholds))->toBe(0.9);
});

it('moves one priority weight and rescales the others to sum 1', function (): void {
    $weights = ['impact' => 0.40, 'urgency' => 0.35, 'tier' => 0.15, 'age' => 0.10];
    $up = PriorityExperiment::shifted($weights, 'impact', 0.1);
    $down = PriorityExperiment::shifted($weights, 'age', -0.1);

    expect($up['impact'])->toBe(0.5)
        ->and($up['urgency'])->toEqualWithDelta(0.35 * 0.5 / 0.6, 1e-9)
        ->and(array_sum($up))->toEqualWithDelta(1.0, 1e-9)
        ->and($down['age'])->toBe(0.0)
        ->and(array_sum($down))->toEqualWithDelta(1.0, 1e-9)
        ->and(PriorityExperiment::shifted(['impact' => 0.95, 'urgency' => 0.05, 'tier' => 0.0, 'age' => 0.0], 'impact', 0.1)['impact'])->toBe(1.0);
});

it('replays arrivals, resolves finished tickets and counts overflows', function (): void {
    $workload = new Workload(
        agents: [['id' => 'a', 'capacity' => 1, 'skills' => ['x']], ['id' => 'b', 'capacity' => 1, 'skills' => ['x']]],
        categorySkills: ['x' => ['x']],
        tickets: array_map(fn (int $i): array => ['id' => "t{$i}", 'arrival_s' => $i * 10, 'category' => 'x', 'handling_s' => $i === 4 ? 5 : 100, 'impact' => 1, 'urgency' => 1, 'tier' => 'standard', 'waited_h' => 0.0], [1, 2, 3, 4]),
    );

    $baseline = (new AssignmentReplay)->run(new LeastLoadedAgent, $workload);
    $roundRobin = (new AssignmentReplay)->run(new RoundRobinPolicy, $workload);

    // Both agents are full after two tickets: the baseline leaves the rest unassigned, round robin overloads.
    expect($baseline['unassigned'])->toBe(2)
        ->and($baseline['overflows'])->toBe(0)
        ->and($roundRobin['overflows'])->toBe(2)
        ->and($roundRobin['snapshots'][3]['open'])->toBe(['a' => 2, 'b' => 2])
        ->and($baseline['snapshots'])->toHaveCount(4);
});

it('frees an agent once the handling time has passed', function (): void {
    $workload = new Workload(
        agents: [['id' => 'a', 'capacity' => 1, 'skills' => []]],
        categorySkills: ['g' => []],
        tickets: [
            ['id' => 't1', 'arrival_s' => 0, 'category' => 'g', 'handling_s' => 60, 'impact' => 1, 'urgency' => 1, 'tier' => 'standard', 'waited_h' => 0.0],
            ['id' => 't2', 'arrival_s' => 60, 'category' => 'g', 'handling_s' => 60, 'impact' => 1, 'urgency' => 1, 'tier' => 'standard', 'waited_h' => 0.0],
        ],
    );

    $replay = (new AssignmentReplay)->run(new LeastLoadedAgent, $workload);

    expect($replay['unassigned'])->toBe(0)
        ->and($replay['assigned'])->toBe(['a' => 2])
        ->and(array_column($replay['snapshots'], 'open'))->toBe([['a' => 1], ['a' => 1]]);
});
