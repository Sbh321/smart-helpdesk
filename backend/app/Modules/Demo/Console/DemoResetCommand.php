<?php

declare(strict_types=1);

namespace App\Modules\Demo\Console;

use App\Modules\Demo\Seeding\DemoCatalogue;
use App\Modules\Demo\Support\DemoReset;
use Illuminate\Console\Command;

/**
 * `php artisan demo:reset` (`just demo-reset`): drops the `acme` and `globex` workspaces and replays the
 * demo dataset (roadmap/11-demo-dataset.md). Other workspaces are untouched. Refuses in production
 * unless DEMO_INSTANCE=true and --force.
 */
final class DemoResetCommand extends Command
{
    protected $signature = 'demo:reset
        {--force : Required on a production instance declared a demo (DEMO_INSTANCE=true)}
        {--seed= : Random seed (default 2026)}
        {--history= : Closed tickets before the live window (default DEMO_HISTORY_TICKETS or 180)}
        {--no-attachments : Skip the uploads to object storage}';

    protected $description = 'Drop and rebuild the demo workspaces (acme, globex)';

    public function handle(DemoReset $reset): int
    {
        $refusal = DemoReset::refusal((bool) $this->option('force'));
        if ($refusal !== null) {
            $this->components->error($refusal);

            return self::FAILURE;
        }

        $started = hrtime(true);
        $this->components->info('Rebuilding the demo workspaces '.implode(' and ', array_keys(DemoCatalogue::WORKSPACES)).' …');
        $result = $reset(
            is_numeric($this->option('seed')) ? (int) $this->option('seed') : null,
            is_numeric($this->option('history')) ? (int) $this->option('history') : null,
            ! $this->option('no-attachments'),
        );
        $total = round((hrtime(true) - $started) / 1e9, 1);

        foreach ($result['seconds'] as $phase => $seconds) {
            $this->components->twoColumnDetail(ucfirst($phase), $seconds.' s');
        }
        $this->components->twoColumnDetail('<options=bold>Total</>', $total.' s');
        foreach ($result['stats'] as $name => $value) {
            $this->components->twoColumnDetail(str_replace('_', ' ', ucfirst($name)), (string) $value);
        }

        $password = (string) config('helpdesk.demo.password');
        $app = 'https://'.config('helpdesk.hosts.app');
        $this->newLine();
        $this->table(['Where', 'Email', 'Role'], [
            ['https://'.config('helpdesk.hosts.admin'), DemoCatalogue::PLATFORM_ADMIN, 'Platform Super Admin'],
            ...array_map(fn (string $email, array $staff): array => ["{$app}/acme", $email, ucfirst($staff[1])], array_keys(DemoCatalogue::STAFF), DemoCatalogue::STAFF),
            ["{$app}/acme", 'arjun@acme.test (and asha, bikram, chen, deepa, elena, farid, grace)', 'Agent'],
            ...array_map(fn (string $email, array $staff): array => ["{$app}/globex", $email, ucfirst($staff[1])], array_keys(DemoCatalogue::GLOBEX_STAFF), DemoCatalogue::GLOBEX_STAFF),
        ]);
        $this->line("Password for every account: <options=bold>{$password}</> (an existing platform admin keeps its own password in production)");

        if ($result['api_client_id'] !== null) {
            $this->newLine();
            $this->components->twoColumnDetail('OAuth client "'.DemoCatalogue::API_CLIENT.'" id', $result['api_client_id']);
            $this->components->twoColumnDetail('Client secret (shown once)', (string) $result['api_client_secret']);
        }

        return self::SUCCESS;
    }
}
