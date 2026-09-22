<?php

declare(strict_types=1);

namespace App\Modules\Demo\Support;

use App\Modules\Demo\Seeding\DemoCatalogue;
use App\Modules\Sla\Jobs\EvaluateSlaTimers;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;

/**
 * "Advance the clock" for the SLA step of the demo without touching the clock: the unfinished SLA
 * timers of the demo workspaces move `$minutes` into the past (start, warning point, due time and an
 * open pause alike), then the SLA sweep runs once for those workspaces at the real "now", exactly as
 * the minute scheduler would. Warnings, breaches, notifications and webhook deliveries are therefore
 * the application's own. Other workspaces are not touched.
 */
final readonly class DemoTick
{
    public function __construct(private Container $container) {}

    /**
     * @return array<string, array{timers: int, warned: int, breached: int}> per workspace slug
     */
    public function __invoke(int $minutes): array
    {
        $results = [];
        foreach (array_keys(DemoCatalogue::WORKSPACES) as $slug) {
            $tenant = Tenant::findBySlug($slug);
            if ($tenant === null) {
                continue;
            }

            $results[$slug] = $tenant->run(function () use ($tenant, $minutes): array {
                $interval = sprintf('%d minutes', $minutes);
                $count = fn (string $type): int => DB::table('sla_events')->where('type', $type)->count();
                [$warned, $breached] = [$count('warning'), $count('breached')];

                $timers = DB::table('ticket_sla_timers')
                    ->whereIn('state', ['running', 'warning', 'paused'])
                    ->update([
                        'started_at' => DB::raw("started_at - interval '{$interval}'"),
                        'warning_at' => DB::raw("warning_at - interval '{$interval}'"),
                        'due_at' => DB::raw("due_at - interval '{$interval}'"),
                        'paused_at' => DB::raw("paused_at - interval '{$interval}'"),
                    ]);

                $this->container->call([new EvaluateSlaTimers((string) $tenant->getKey()), 'handle']);

                return ['timers' => $timers, 'warned' => $count('warning') - $warned, 'breached' => $count('breached') - $breached];
            });
        }

        return $results;
    }
}
