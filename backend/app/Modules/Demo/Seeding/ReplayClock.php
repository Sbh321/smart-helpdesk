<?php

declare(strict_types=1);

namespace App\Modules\Demo\Seeding;

use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;

/**
 * Time during the demo replay. Everything the application stamps comes from one instant that the
 * replay moves forward step by step: the injectable `Clock` (domain time), Carbon's test "now"
 * (Eloquent timestamps) and the PostgreSQL session setting `app.occurred_at`, which the change-capture
 * trigger uses instead of clock_timestamp() when it is set (Reporting migration
 * 2026_09_21_190000_change_capture_replay_time). `restore()` puts all three back.
 */
final class ReplayClock
{
    private FrozenClock $frozen;

    private ?Clock $previousClock;

    private ?Carbon $previousCarbon;

    private ?CarbonImmutable $previousImmutable;

    public function __construct(private readonly Container $container, CarbonImmutable $start)
    {
        $this->previousClock = $container->bound(Clock::class) ? $container->make(Clock::class) : null;
        $this->previousCarbon = Carbon::getTestNow() instanceof Carbon ? Carbon::getTestNow() : null;
        $this->previousImmutable = CarbonImmutable::getTestNow() instanceof CarbonImmutable ? CarbonImmutable::getTestNow() : null;
        $this->frozen = new FrozenClock($start);
        $container->instance(Clock::class, $this->frozen);
        $this->set($start);
    }

    public function now(): CarbonImmutable
    {
        return $this->frozen->now();
    }

    public function set(CarbonImmutable $instant): void
    {
        $this->frozen->set($instant);
        Carbon::setTestNow($instant);
        CarbonImmutable::setTestNow($instant);
        DB::select("SELECT set_config('app.occurred_at', ?, false)", [$instant->toIso8601ZuluString('microsecond')]);
    }

    public function restore(): void
    {
        DB::select("SELECT set_config('app.occurred_at', '', false)");
        Carbon::setTestNow($this->previousCarbon);
        CarbonImmutable::setTestNow($this->previousImmutable);
        if ($this->previousClock !== null) {
            $this->container->instance(Clock::class, $this->previousClock);
        }
    }
}
