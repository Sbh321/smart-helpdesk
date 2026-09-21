<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Console;

use App\Modules\Integrations\Domain\Webhooks\DeliveryState;
use App\Modules\Integrations\Jobs\DeliverWebhook;
use App\Modules\Integrations\Models\WebhookDelivery;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Time\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Every minute (docs/11-operations/scheduler.md): queues the next attempt of every delivery whose
 * retry is due, per workspace with tenant-tagged jobs. It also re-queues `pending` deliveries whose
 * job was lost (worker crash, flushed queue) once their lease is five minutes old; the claim in
 * `DeliverWebhook` makes a duplicate job harmless.
 */
final class RetryDueWebhooks extends Command
{
    /** A pending delivery not claimed for this long is considered lost and queued again. */
    public const STALE_SECONDS = 300;

    protected $signature = 'webhooks:retry-due';

    protected $description = 'Queue webhook deliveries whose next attempt is due';

    public function handle(Clock $clock): int
    {
        $now = $clock->now();

        // MVP-SHORTCUT: this cross-tenant read works because row-level security is not enabled yet;
        // V1: M3-07 gives the sweep a privileged read of the due tenant ids (same as sla:evaluate).
        $tenantIds = $this->due(DB::table('webhook_deliveries'), $now)->distinct()->pluck('tenant_id');

        $queued = 0;
        foreach (Tenant::active()->whereKey($tenantIds)->cursor() as $tenant) {
            try {
                $queued += (int) $tenant->run(fn (): int => $this->queueTenant($now));
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $this->components->info("Queued {$queued} webhook deliveries.");

        return self::SUCCESS;
    }

    private function queueTenant(CarbonImmutable $now): int
    {
        $count = 0;
        $this->due(WebhookDelivery::query()->toBase(), $now)
            ->select(['id', 'subscription_id', 'state'])
            ->chunkById(500, function ($rows) use ($now, &$count): void {
                foreach ($rows as $row) {
                    if ($row->state === DeliveryState::Failed->value) {
                        $moved = WebhookDelivery::query()->whereKey($row->id)
                            ->where('state', DeliveryState::Failed->value)
                            ->update(['state' => DeliveryState::Pending->value, 'next_attempt_at' => $now, 'updated_at' => $now]);
                        if ($moved !== 1) {
                            continue;
                        }
                    } else {
                        WebhookDelivery::query()->whereKey($row->id)->update(['next_attempt_at' => $now, 'updated_at' => $now]);
                    }

                    DeliverWebhook::dispatch((string) $row->id, (string) $row->subscription_id);
                    $count++;
                }
            }, 'id');

        return $count;
    }

    private function due(Builder $query, CarbonImmutable $now): Builder
    {
        return $query->where(function (Builder $due) use ($now): void {
            $due->where(function (Builder $failed) use ($now): void {
                $failed->where('state', DeliveryState::Failed->value)->where('next_attempt_at', '<=', $now);
            })->orWhere(function (Builder $stale) use ($now): void {
                $stale->where('state', DeliveryState::Pending->value)
                    ->where('next_attempt_at', '<=', $now->subSeconds(self::STALE_SECONDS));
            });
        });
    }
}
