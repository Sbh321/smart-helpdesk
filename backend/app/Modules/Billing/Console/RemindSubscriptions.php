<?php

declare(strict_types=1);

namespace App\Modules\Billing\Console;

use App\Modules\Billing\Enums\SubscriptionState;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Notifications\SubscriptionReminder;
use App\Modules\Billing\Support\BillingContacts;
use App\Modules\Billing\Support\Subscriptions;
use App\Modules\Tenancy\Enums\TenantStatus;
use Illuminate\Console\Command;

/**
 * Daily reminders to a workspace's billing people (ADR-0025 §6): 7, 3 and 1 days before its
 * subscription ends, when grace starts and when it becomes read-only. Each reminder is sent once for an
 * end date (`subscriptions.reminders`, cleared when the end date moves); a missed day sends only the
 * reminder that applies now. The state itself never depends on this job.
 */
final class RemindSubscriptions extends Command
{
    /** Days before the end at which a reminder goes out, largest first. */
    private const BEFORE_END = [7, 3, 1];

    protected $signature = 'billing:remind';

    protected $description = 'Email workspaces whose trial or subscription is ending, in grace or read-only.';

    public function handle(Subscriptions $subscriptions, BillingContacts $contacts): int
    {
        $sent = 0;
        Subscription::query()->with(['plan', 'tenant'])
            ->whereHas('tenant', fn ($query) => $query->where('status', TenantStatus::Active))
            ->chunkById(100, function ($chunk) use ($subscriptions, $contacts, &$sent): void {
                foreach ($chunk as $subscription) {
                    /** @var Subscription $subscription */
                    $status = $subscriptions->status($subscription);
                    $key = self::reminderFor($status->state, $status->daysLeft());
                    if ($key === null || in_array($key, $subscription->reminders, true)) {
                        continue;
                    }
                    $contacts->notify($subscription->tenant, new SubscriptionReminder($key, $subscription->tenant, $status));
                    $subscription->forceFill(['reminders' => [...$subscription->reminders, $key]])->save();
                    $sent++;
                }
            });

        $this->info("Sent {$sent} subscription reminders.");

        return self::SUCCESS;
    }

    public static function reminderFor(SubscriptionState $state, ?int $daysLeft): ?string
    {
        return match ($state) {
            SubscriptionState::Grace => 'grace',
            SubscriptionState::Expired => 'read-only',
            SubscriptionState::Trialing, SubscriptionState::Active => self::beforeEnd($daysLeft),
            SubscriptionState::None => null,
        };
    }

    private static function beforeEnd(?int $daysLeft): ?string
    {
        $due = null;
        foreach (self::BEFORE_END as $days) {
            if ($daysLeft !== null && $daysLeft <= $days) {
                $due = "ends-in-{$days}";
            }
        }

        return $due;
    }
}
