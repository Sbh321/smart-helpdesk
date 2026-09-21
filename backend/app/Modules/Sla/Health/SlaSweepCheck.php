<?php

declare(strict_types=1);

namespace App\Modules\Sla\Health;

use App\Modules\Sla\Jobs\EvaluateSlaTimers;
use App\Support\Time\Clock;
use Illuminate\Support\Facades\Cache;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

final class SlaSweepCheck extends Check
{
    public function __construct(private readonly Clock $clock) {}

    public function run(): Result
    {
        // Valkey hands the stored timestamp back as a numeric string; the array store keeps the int.
        $heartbeat = Cache::get(EvaluateSlaTimers::HEARTBEAT_KEY);
        if (! is_numeric($heartbeat)) {
            return Result::make()->failed('The SLA sweep has not run yet.');
        }
        $last = (int) $heartbeat;

        $ageSeconds = max(0, $this->clock->now()->timestamp - $last);
        if ($ageSeconds > 180) {
            return Result::make()->failed('The SLA sweep is more than three minutes old.')
                ->meta(['age_seconds' => $ageSeconds]);
        }

        return Result::make()->ok()->shortSummary('recent')
            ->meta(['age_seconds' => $ageSeconds]);
    }
}
