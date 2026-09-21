<?php

declare(strict_types=1);

use App\Modules\Automation\Console\Experiments\Datasets\DuplicatePairsGenerator;
use App\Modules\Automation\Console\Experiments\Datasets\TicketTextGenerator;
use App\Modules\Automation\Console\Experiments\Datasets\WorkloadGenerator;
use App\Modules\Reporting\Console\Experiments\HistoryPlan;
use App\Support\Experiments\SeededRandom;
use Carbon\CarbonImmutable;

/** @return list<array{category: string, a: array{title: string, description: string}, b: array{title: string, description: string}}> */
function handWrittenPairs(int $count = 40): array
{
    return array_map(fn (int $i): array => [
        'category' => 'account',
        'a' => ['title' => "Hand written {$i}", 'description' => 'First wording.'],
        'b' => ['title' => "Written by hand {$i}", 'description' => 'Second wording.'],
    ], range(1, $count));
}

it('generates the same workload for the same seed and another for a new seed', function (): void {
    $generator = new WorkloadGenerator;

    expect($generator->generate(42))->toBe($generator->generate(42))
        ->and($generator->generate(42)['tickets'])->not->toBe($generator->generate(43)['tickets']);
});

it('generates 8 agents, 6 categories and 500 tickets of the documented shape', function (): void {
    $workload = (new WorkloadGenerator)->generate(42);
    $arrivals = array_column($workload['tickets'], 'arrival_s');
    $sorted = $arrivals;
    sort($sorted);

    expect($workload['agents'])->toHaveCount(8)
        ->and($workload['categories'])->toHaveCount(6)
        ->and($workload['tickets'])->toHaveCount(500)
        ->and($arrivals)->toBe($sorted)
        ->and(min(array_column($workload['agents'], 'capacity')))->toBeGreaterThanOrEqual(5)
        ->and(max(array_column($workload['agents'], 'capacity')))->toBeLessThanOrEqual(12)
        ->and(min(array_column($workload['tickets'], 'handling_s')))->toBeGreaterThanOrEqual(60)
        ->and(array_unique(array_column($workload['tickets'], 'category')))->each->toBeIn(array_keys(WorkloadGenerator::CATEGORIES))
        ->and(array_keys($workload['tickets'][0]))->toBe(['id', 'arrival_s', 'category', 'handling_s', 'impact', 'urgency', 'tier', 'waited_h']);

    foreach ($workload['tickets'] as $ticket) {
        expect($ticket['impact'])->toBeBetween(1, 4)->and($ticket['urgency'])->toBeBetween(1, 4)
            ->and($ticket['tier'])->toBeIn(['standard', 'premium', 'enterprise'])->and($ticket['waited_h'])->toBeBetween(0.0, 96.0);
    }
    foreach (WorkloadGenerator::SKILLS as $skill) {
        expect(count(array_filter($workload['agents'], fn (array $agent): bool => in_array($skill, $agent['skills'], true))))
            ->toBeGreaterThanOrEqual(2);
    }
});

it('generates 300 labelled pairs with the documented split', function (): void {
    $pairs = (new DuplicatePairsGenerator)->pairs(42, handWrittenPairs());
    $count = fn (callable $filter): int => count(array_filter($pairs, $filter));

    expect($pairs)->toHaveCount(300)
        ->and($count(fn (array $p): bool => $p['label'] === 'duplicate'))->toBe(100)
        ->and($count(fn (array $p): bool => $p['label'] === 'duplicate' && $p['source'] === 'hand-written'))->toBe(40)
        ->and($count(fn (array $p): bool => $p['label'] === 'non_duplicate'))->toBe(200)
        ->and($count(fn (array $p): bool => $p['label'] === 'non_duplicate' && $p['category_a'] === $p['category_b']))->toBe(50)
        ->and(array_column($pairs, 'id'))->toBe(array_map(fn (int $i): string => sprintf('P%03d', $i), range(1, 300)))
        ->and($pairs)->toBe((new DuplicatePairsGenerator)->pairs(42, handWrittenPairs()))
        ->and($pairs)->not->toBe((new DuplicatePairsGenerator)->pairs(7, handWrittenPairs()));
});

it('refuses a hand-written file with the wrong number of pairs', function (): void {
    expect(fn () => (new DuplicatePairsGenerator)->pairs(42, handWrittenPairs(39)))->toThrow(InvalidArgumentException::class);
});

it('makes a generated duplicate by dropping and swapping words only', function (): void {
    $random = new SeededRandom(3);
    $original = (new TicketTextGenerator)->ticket($random, 'billing');
    $variant = (new DuplicatePairsGenerator)->variant($random, $original);
    $words = fn (string $text): array => explode(' ', $text);

    expect(array_diff($words($variant['description']), $words($original['description'])))->toBe([])
        ->and(count($words($variant['description'])))->toBeLessThan(count($words($original['description'])))
        ->and(array_diff($words($variant['title']), $words($original['title'])))->toBe([]);
});

it('generates a repeatable 5 000-ticket haystack', function (): void {
    $generator = new DuplicatePairsGenerator;
    $haystack = $generator->haystack(43);

    expect($haystack)->toHaveCount(5000)
        ->and($haystack[0])->toHaveKeys(['id', 'category', 'title', 'description'])
        ->and(array_slice($generator->haystack(43), 0, 50))->toBe(array_slice($haystack, 0, 50));
});

it('plans ticket histories in time order that follow the lifecycle', function (): void {
    $end = CarbonImmutable::parse('2026-09-21 00:00:00', 'UTC');
    $steps = (new HistoryPlan)->steps(new SeededRandom(42), 20, 30, $end, ['team-a', 'team-b']);
    $again = (new HistoryPlan)->steps(new SeededRandom(42), 20, 30, $end, ['team-a', 'team-b']);
    $allowed = ['open' => ['assigned'], 'assigned' => ['in_progress'], 'in_progress' => ['pending', 'resolved'], 'pending' => ['in_progress'], 'resolved' => ['closed', 'in_progress']];

    expect(array_map(fn (array $s): array => [$s['ticket'], $s['at']->toIso8601String(), $s['type']], $steps))
        ->toBe(array_map(fn (array $s): array => [$s['ticket'], $s['at']->toIso8601String(), $s['type']], $again));

    $status = [];
    $previous = null;
    foreach ($steps as $step) {
        expect($step['at']->lessThan($end))->toBeTrue();
        if ($previous !== null) {
            expect($step['at']->greaterThanOrEqualTo($previous))->toBeTrue();
        }
        $previous = $step['at'];
        if ($step['type'] === 'created') {
            expect($status)->not->toHaveKey($step['ticket']);
            $status[$step['ticket']] = 'open';
        } elseif (isset($step['set']['status'])) {
            expect($step['set']['status'])->toBeIn($allowed[$status[$step['ticket']]]);
            $status[$step['ticket']] = $step['set']['status'];
        }
    }
    expect($status)->toHaveCount(20);
});
