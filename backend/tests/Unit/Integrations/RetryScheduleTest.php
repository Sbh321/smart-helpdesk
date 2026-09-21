<?php

declare(strict_types=1);

use App\Modules\Integrations\Domain\Webhooks\RetrySchedule;
use Carbon\CarbonImmutable;

it('retries at +1 m, +5 m, +30 m, +2 h and +12 h, then gives up', function (): void {
    $schedule = new RetrySchedule(0.0);
    $now = CarbonImmutable::parse('2026-09-21 10:00:00', 'UTC');

    expect($schedule->nextAttemptAt(1, $now)?->toIso8601ZuluString())->toBe('2026-09-21T10:01:00Z')
        ->and($schedule->nextAttemptAt(2, $now)?->toIso8601ZuluString())->toBe('2026-09-21T10:05:00Z')
        ->and($schedule->nextAttemptAt(3, $now)?->toIso8601ZuluString())->toBe('2026-09-21T10:30:00Z')
        ->and($schedule->nextAttemptAt(4, $now)?->toIso8601ZuluString())->toBe('2026-09-21T12:00:00Z')
        ->and($schedule->nextAttemptAt(5, $now)?->toIso8601ZuluString())->toBe('2026-09-21T22:00:00Z')
        ->and($schedule->nextAttemptAt(6, $now))->toBeNull()
        ->and($schedule->nextAttemptAt(0, $now))->toBeNull();
});

it('spreads each delay by at most ±20 %', function (): void {
    $low = new RetrySchedule(0.2, fn (int $min, int $max): int => $min);
    $high = new RetrySchedule(0.2, fn (int $min, int $max): int => $max);

    foreach (RetrySchedule::DELAYS as $index => $base) {
        expect($low->delaySeconds($index + 1))->toBe((int) round($base * 0.8))
            ->and($high->delaySeconds($index + 1))->toBe((int) round($base * 1.2));
    }

    $random = new RetrySchedule(0.2);
    foreach (range(1, 50) as $ignored) {
        expect($random->delaySeconds(1))->toBeGreaterThanOrEqual(48)->toBeLessThanOrEqual(72);
    }
});
