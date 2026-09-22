<?php

declare(strict_types=1);

use App\Modules\Demo\Seeding\DemoCatalogue;
use App\Modules\Demo\Seeding\DemoPlan;

it('plans 120 live tickets numbered in creation order with the target distributions', function (): void {
    $tickets = (new DemoPlan(2026, 30))->tickets();
    $live = array_values(array_filter($tickets, fn (array $t): bool => $t['number'] >= DemoCatalogue::FIRST_LIVE_NUMBER));
    $count = fn (string $key): array => (function (array $values): array {
        ksort($values);

        return $values;
    })(array_count_values(array_column($live, $key)));

    $statuses = DemoPlan::STATUS_TARGETS;
    ksort($statuses);
    $categories = DemoPlan::CATEGORY_TARGETS;
    ksort($categories);

    expect($tickets)->toHaveCount(150)
        ->and(array_column($tickets, 'number'))->toBe(range(971, 1120))
        ->and($count('status'))->toBe($statuses)
        ->and($count('level'))->toBe(DemoPlan::PRIORITY_TARGETS)
        ->and($count('category'))->toBe($categories)
        ->and($count('via'))->toBe(['api' => 20, 'ui' => 100]);

    // Creation order follows the numbers, and every step of a ticket comes after the previous one.
    $ages = array_column($tickets, 'ago');
    for ($i = 1; $i < count($ages); $i++) {
        expect($ages[$i])->toBeLessThan($ages[$i - 1]);
    }

    // Age buckets of the live tickets (dataset page: 25 / 35 / 35 / 25).
    $buckets = ['0-1d' => 0, '1-3d' => 0, '3-7d' => 0, '7-30d' => 0];
    foreach ($live as $ticket) {
        $days = $ticket['ago'] / DemoPlan::DAY;
        $buckets[match (true) {
            $days < 1 => '0-1d',
            $days < 3 => '1-3d',
            $days < 7 => '3-7d',
            default => '7-30d',
        }]++;
    }
    expect($buckets)->toBe(['0-1d' => 25, '1-3d' => 35, '3-7d' => 35, '7-30d' => 25]);
});

it('is deterministic for a seed and different for another', function (): void {
    $titles = fn (int $seed): array => array_column((new DemoPlan($seed, 20))->tickets(), 'title');

    expect($titles(2026))->toBe($titles(2026))
        ->and($titles(2026))->not->toBe($titles(7));
});

it('gives every ticket inputs for which the baseline formula computes its level', function (): void {
    foreach ((new DemoPlan(2026, 30))->tickets() as $ticket) {
        $tier = DemoPlan::tierAt($ticket['contact'], $ticket['ago']);
        expect(DemoPlan::inputsFor($ticket['level'], $tier))->toContain([$ticket['impact'], $ticket['urgency']]);
    }

    // Worked examples of docs/05-algorithms/priority-scoring.md: (4, 4) enterprise is P1, (1, 1) standard P4.
    expect(DemoPlan::inputsFor('P1', 'enterprise'))->toContain([4, 4])
        ->and(DemoPlan::inputsFor('P4', 'standard'))->toContain([1, 1])
        ->and(DemoPlan::inputsFor('P4', 'enterprise'))->toBe([[1, 1]]);
});

it('follows the scripted moves of the moving contact and the Hooli upgrade', function (): void {
    expect(DemoPlan::organisationAt(DemoCatalogue::MOVING_CONTACT, 30 * DemoPlan::DAY))->toBe('initech')
        ->and(DemoPlan::organisationAt(DemoCatalogue::MOVING_CONTACT, 20 * DemoPlan::DAY))->toBe('hooli')
        ->and(DemoPlan::organisationAt(DemoCatalogue::MOVING_CONTACT, DemoPlan::DAY))->toBe('stark')
        ->and(DemoPlan::organisationAt('Ethan Brooks', DemoPlan::DAY))->toBeNull()
        ->and(DemoPlan::tierAt('Pooja Bista', 21 * DemoPlan::DAY))->toBe('standard')
        ->and(DemoPlan::tierAt('Pooja Bista', 19 * DemoPlan::DAY))->toBe('premium')
        ->and(DemoPlan::targets('P1', 40 * DemoPlan::DAY))->toBe([30, 240])
        ->and(DemoPlan::targets('P1', DemoPlan::DAY))->toBe([30, 480]);

    expect(fn () => DemoPlan::organisationAt('Nobody', 1))->toThrow(LogicException::class);
});
