<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Console;

use App\Support\Time\Clock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Daily at 03:40 (docs/11-operations/scheduler.md): deletes webhook deliveries older than the
 * retention period (30 days), in batches so the table is never locked for long. Subscriptions keep
 * their counters; RPT-I01 reports over the retained window.
 */
final class PruneWebhookDeliveries extends Command
{
    protected $signature = 'webhooks:prune {--days= : Retention in days (default helpdesk.webhooks.retention_days)}';

    protected $description = 'Delete webhook deliveries older than the retention period';

    public function handle(Clock $clock): int
    {
        $days = max(1, (int) ($this->option('days') ?? config('helpdesk.webhooks.retention_days', 30)));
        $before = $clock->now()->subDays($days);

        // MVP-SHORTCUT: a cross-tenant delete that works because row-level security is not enabled yet;
        // V1: M3-07 runs retention per tenant or under a privileged role.
        $deleted = 0;
        do {
            $batch = DB::delete(
                'DELETE FROM webhook_deliveries WHERE id IN (SELECT id FROM webhook_deliveries WHERE created_at < ? LIMIT 5000)',
                [$before],
            );
            $deleted += $batch;
        } while ($batch === 5000);

        $this->components->info("Deleted {$deleted} webhook deliveries older than {$days} days.");

        return self::SUCCESS;
    }
}
