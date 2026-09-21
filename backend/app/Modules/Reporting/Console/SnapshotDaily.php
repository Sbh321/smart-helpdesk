<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Console;

use App\Modules\Reporting\Support\DailySnapshotBuilder;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Time\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Writes each workspace's snapshot of its local yesterday (or `--day`). Scheduled hourly, so every
 * workspace is covered soon after its own midnight; a run for a day already written replaces it.
 */
final class SnapshotDaily extends Command
{
    protected $signature = 'reports:snapshot-daily {--tenant= : Limit to a workspace slug} {--day= : YYYY-MM-DD in the workspace time zone}';

    protected $description = 'Write the end-of-day report snapshot of each workspace';

    public function handle(DailySnapshotBuilder $builder, Clock $clock): int
    {
        $tenants = Tenant::active();
        if (is_string($slug = $this->option('tenant')) && $slug !== '') {
            $tenants->where('slug', $slug);
        }
        $failed = false;

        foreach ($tenants->cursor() as $tenant) {
            $timezone = (string) ($tenant->timezone ?: 'UTC');
            $day = is_string($option = $this->option('day')) && $option !== ''
                ? CarbonImmutable::parse($option, $timezone)
                : $clock->now()->setTimezone($timezone)->subDay();
            try {
                $rows = $tenant->run(fn (): int => $builder->write((string) $tenant->id, $day->startOfDay(), $timezone, $clock->now()));
                $this->components->twoColumnDetail("{$tenant->slug} {$day->toDateString()}", "{$rows} rows");
            } catch (Throwable $exception) {
                report($exception);
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
