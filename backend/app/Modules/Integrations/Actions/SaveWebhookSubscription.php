<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Actions;

use App\Modules\Audit\Audit;
use App\Modules\Integrations\Domain\Webhooks\WebhookSigner;
use App\Modules\Integrations\Models\WebhookSubscription;
use App\Modules\Integrations\Webhooks\UrlGuard;
use Illuminate\Support\Facades\DB;

/**
 * Creates a subscription or edits its name, URL and events (docs/07-api/webhooks.md). A new or
 * changed URL passes the SSRF guard first (422 `webhook_url_rejected`). A new subscription gets a
 * random secret, readable from the returned model during this request only. Both are audited; the
 * audit entry never holds the secret.
 */
final readonly class SaveWebhookSubscription
{
    public function __construct(private UrlGuard $guard) {}

    /**
     * @param  array{name?: string, url?: string, events?: list<string>}  $data
     */
    public function __invoke(array $data, ?WebhookSubscription $subscription = null, ?string $actorId = null): WebhookSubscription
    {
        $creating = $subscription === null;
        $subscription ??= new WebhookSubscription;

        if (isset($data['url']) && ($creating || $data['url'] !== $subscription->url)) {
            $this->guard->check($data['url']);
        }

        return DB::transaction(function () use ($data, $subscription, $creating, $actorId): WebhookSubscription {
            $subscription->fill(array_intersect_key($data, array_flip(['name', 'url', 'events'])));
            if (isset($data['events'])) {
                $subscription->events = array_values(array_unique($data['events']));
            }

            if ($creating) {
                $subscription->forceFill([
                    'secret' => WebhookSigner::newSecret(),
                    'api_version' => 'v1',
                    'is_active' => true,
                    'consecutive_failures' => 0,
                    'created_by_user_id' => $actorId,
                ]);
            }

            $changes = $creating ? [] : $this->changes($subscription);
            $subscription->save();

            if ($creating) {
                Audit::record('webhook.created', $subscription, ['name' => $subscription->name, 'url' => $subscription->url, 'events' => $subscription->events]);
            } elseif ($changes !== []) {
                Audit::record('webhook.updated', $subscription, $changes);
            }

            return $subscription;
        });
    }

    /**
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function changes(WebhookSubscription $subscription): array
    {
        $changes = [];
        foreach (['name', 'url', 'events'] as $field) {
            if ($subscription->isDirty($field)) {
                $changes[$field] = ['old' => $subscription->getOriginal($field), 'new' => $subscription->getAttribute($field)];
            }
        }

        return $changes;
    }
}
