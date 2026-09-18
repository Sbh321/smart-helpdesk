<?php

declare(strict_types=1);

namespace App\Support\Health;

use App\Modules\Media\Health\StorageCheck;
use Spatie\Health\Checks\Checks\CacheCheck;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\DebugModeCheck;
use Spatie\Health\Checks\Checks\EnvironmentCheck;
use Spatie\Health\Checks\Checks\HorizonCheck;
use Spatie\Health\Checks\Checks\QueueCheck;
use Spatie\Health\Checks\Checks\RedisCheck;
use Spatie\Health\Checks\Checks\ScheduleCheck;
use Spatie\Health\Checks\Checks\UsedDiskSpaceCheck;
use Spatie\Health\Facades\Health;

/**
 * The dependency checks behind /v1/health (docs/11-operations/observability.md §Health checks).
 * Checks for backups, Reverb and the SLA sweep are added by the tasks that build those parts.
 */
final class HealthChecks
{
    public static function register(): void
    {
        $production = app()->environment('production');

        Health::checks([
            DatabaseCheck::new(),
            RedisCheck::new(),
            CacheCheck::new(),
            StorageCheck::new()->disk((string) config('filesystems.default')),
            QueueCheck::new()->onQueue(config('helpdesk.health.queues'))->failWhenHealthJobTakesLongerThanMinutes(5),
            ScheduleCheck::new()->heartbeatMaxAgeInMinutes(2),
            HorizonCheck::new(),
            UsedDiskSpaceCheck::new()->warnWhenUsedSpaceIsAbovePercentage(70)->failWhenUsedSpaceIsAbovePercentage(90),
            DebugModeCheck::new()->if($production),
            EnvironmentCheck::new()->expectEnvironment('production')->if($production),
        ]);
    }
}
