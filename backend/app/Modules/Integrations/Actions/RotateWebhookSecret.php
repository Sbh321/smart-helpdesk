<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Actions;

use App\Modules\Audit\Audit;
use App\Modules\Integrations\Domain\Webhooks\WebhookSigner;
use App\Modules\Integrations\Models\WebhookSubscription;
use App\Support\Time\Clock;

/**
 * Replaces the signing secret. The old secret stays valid for 24 hours: deliveries in that window
 * carry two `v1=` signatures, so the receiver can switch without dropping events
 * (docs/07-api/webhooks.md §Subscription model).
 */
final readonly class RotateWebhookSecret
{
    public const OVERLAP_HOURS = 24;

    public function __construct(private Clock $clock) {}

    public function __invoke(WebhookSubscription $subscription): WebhookSubscription
    {
        $subscription->forceFill([
            'previous_secret' => $subscription->secret,
            'previous_secret_expires_at' => $this->clock->now()->addHours(self::OVERLAP_HOURS),
            'secret' => WebhookSigner::newSecret(),
        ])->save();

        Audit::record('webhook.secret_rotated', $subscription);

        return $subscription;
    }
}
