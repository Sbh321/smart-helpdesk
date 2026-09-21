<?php

declare(strict_types=1);

use App\Modules\Sla\Health\SlaSweepCheck;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Illuminate\Support\Facades\Cache;
use Spatie\Health\Checks\Check;
use Spatie\Health\Facades\Health;

it('registers the SLA sweep heartbeat alongside dependency checks', function (): void {
    $names = Health::registeredChecks()->map(fn (Check $check): string => $check->getName())->all();

    expect($names)->toContain('SlaSweep');
});

it('fails for an absent or stale sweep and passes for a recent one', function (): void {
    $clock = new FrozenClock('2026-09-19 10:00:00');
    $this->app->instance(Clock::class, $clock);
    Cache::forget('sla:last_sweep_at');
    $check = SlaSweepCheck::new();

    expect((string) $check->run()->status)->toBe('failed');
    Cache::put('sla:last_sweep_at', $clock->now()->subSeconds(181)->timestamp, 600);
    expect((string) $check->run()->status)->toBe('failed');
    Cache::put('sla:last_sweep_at', $clock->now()->subSeconds(180)->timestamp, 600);
    expect((string) $check->run()->status)->toBe('ok');
});

it('reads the heartbeat the redis store hands back as a numeric string', function (): void {
    $clock = new FrozenClock('2026-09-19 10:00:00');
    $this->app->instance(Clock::class, $clock);
    config(['cache.default' => 'redis']);
    Cache::put('sla:last_sweep_at', $clock->now()->subSeconds(30)->timestamp, 600);

    expect(Cache::get('sla:last_sweep_at'))->toBeString()
        ->and((string) SlaSweepCheck::new()->run()->status)->toBe('ok');

    Cache::forget('sla:last_sweep_at');
});
