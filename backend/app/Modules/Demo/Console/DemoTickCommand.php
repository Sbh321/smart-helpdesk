<?php

declare(strict_types=1);

namespace App\Modules\Demo\Console;

use App\Modules\Demo\Support\DemoReset;
use App\Modules\Demo\Support\DemoTick;
use Illuminate\Console\Command;

/**
 * `php artisan demo:tick 30` (`just demo-tick 30`): the SLA timers of the demo workspaces behave as if
 * 30 more minutes had passed, then the SLA sweep runs (docs/12-academic/demo-plan.md, minute 10).
 */
final class DemoTickCommand extends Command
{
    protected $signature = 'demo:tick
        {minutes=30 : Minutes to advance, e.g. 30 or 30m}
        {--force : Required on a production instance declared a demo (DEMO_INSTANCE=true)}';

    protected $description = 'Advance the SLA timers of the demo workspaces and run the SLA sweep';

    public function handle(DemoTick $tick): int
    {
        $refusal = DemoReset::refusal((bool) $this->option('force'));
        if ($refusal !== null) {
            $this->components->error($refusal);

            return self::FAILURE;
        }

        $argument = (string) $this->argument('minutes');
        if (preg_match('/^(\d+)\s*(m|min|minutes?)?$/i', trim($argument), $match) !== 1 || (int) $match[1] < 1 || (int) $match[1] > 7 * 24 * 60) {
            $this->components->error('Minutes must be a whole number from 1 to 10080, e.g. 30 or 30m.');

            return self::FAILURE;
        }
        $minutes = (int) $match[1];

        $results = $tick($minutes);
        if ($results === []) {
            $this->components->warn('No demo workspace exists; run demo:reset first.');

            return self::FAILURE;
        }
        foreach ($results as $slug => $result) {
            $this->components->twoColumnDetail($slug, sprintf('%d timers moved %d min; %d warnings, %d breaches', $result['timers'], $minutes, $result['warned'], $result['breached']));
        }

        return self::SUCCESS;
    }
}
