<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Demo\Support\DemoReset;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * `php artisan db:seed --class=DemoSeeder` (`just seed`, first-time setup): the same as `demo:reset`,
 * which drops and rebuilds only the demo workspaces (roadmap/11-demo-dataset.md).
 */
class DemoSeeder extends Seeder
{
    public function run(DemoReset $reset): void
    {
        $refusal = DemoReset::refusal(false);
        if ($refusal !== null) {
            throw new RuntimeException($refusal.' Use `php artisan demo:reset --force` instead.');
        }

        $result = $reset();
        $this->command->info(sprintf('Demo workspaces rebuilt in %.1f s. Every account uses the password "%s".', array_sum($result['seconds']), (string) config('helpdesk.demo.password')));
    }
}
