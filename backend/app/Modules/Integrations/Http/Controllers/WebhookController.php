<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Http\Controllers;

use App\Models\User;
use App\Modules\Audit\Audit;
use App\Modules\Integrations\Actions\QueueWebhookEvent;
use App\Modules\Integrations\Actions\RotateWebhookSecret;
use App\Modules\Integrations\Actions\SaveWebhookSubscription;
use App\Modules\Integrations\Actions\SetWebhookActive;
use App\Modules\Integrations\Domain\Webhooks\DeliveryState;
use App\Modules\Integrations\Domain\Webhooks\WebhookEventType;
use App\Modules\Integrations\Http\Requests\StoreWebhookRequest;
use App\Modules\Integrations\Http\Requests\UpdateWebhookRequest;
use App\Modules\Integrations\Http\Resources\WebhookDeliveryResource;
use App\Modules\Integrations\Http\Resources\WebhookEventTypeResource;
use App\Modules\Integrations\Http\Resources\WebhookSubscriptionResource;
use App\Modules\Integrations\Http\Resources\WebhookSubscriptionWithSecretResource;
use App\Modules\Integrations\Models\WebhookSubscription;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Settings → Developer → Webhooks (docs/07-api/webhooks.md). Permission `integrations.manage`;
 * API clients with the `webhooks:manage` scope may use these routes too.
 */
#[Group('Webhooks')]
final class WebhookController
{
    /**
     * List the workspace's webhook subscriptions, newest first.
     */
    public function index(): AnonymousResourceCollection
    {
        $subscriptions = WebhookSubscription::query()->orderByDesc('created_at')->orderByDesc('id')->get();

        return WebhookSubscriptionResource::collection($subscriptions);
    }

    /**
     * The event catalogue a subscription can listen to.
     */
    public function events(): AnonymousResourceCollection
    {
        return WebhookEventTypeResource::collection(WebhookEventType::subscribable());
    }

    /**
     * Create a webhook subscription.
     *
     * Create a subscription. The response carries `secret`, which is shown only this once.
     */
    #[Response(status: 201, type: WebhookSubscriptionWithSecretResource::class)]
    public function store(StoreWebhookRequest $request, SaveWebhookSubscription $save): JsonResponse
    {
        /** @var array{name: string, url: string, events: list<string>} $data */
        $data = $request->validated();
        $actor = $request->user();

        $subscription = $save($data, null, $actor instanceof User ? $actor->id : null);

        return (new WebhookSubscriptionWithSecretResource($subscription))->response()->setStatusCode(201);
    }

    /** Get a webhook subscription. */
    public function show(WebhookSubscription $webhook): WebhookSubscriptionResource
    {
        return new WebhookSubscriptionResource($webhook);
    }

    /**
     * Edit the name, URL or events of a subscription.
     */
    public function update(UpdateWebhookRequest $request, WebhookSubscription $webhook, SaveWebhookSubscription $save): WebhookSubscriptionResource
    {
        /** @var array{name?: string, url?: string, events?: list<string>} $data */
        $data = $request->validated();

        return new WebhookSubscriptionResource($save($data, $webhook));
    }

    /**
     * Delete a subscription and its delivery log.
     */
    public function destroy(WebhookSubscription $webhook): HttpResponse
    {
        DB::transaction(function () use ($webhook): void {
            Audit::record('webhook.deleted', $webhook, ['name' => $webhook->name, 'url' => $webhook->url]);
            $webhook->delete();
        });

        return response()->noContent();
    }

    /** Enable a webhook subscription. */
    public function enable(WebhookSubscription $webhook, SetWebhookActive $set): WebhookSubscriptionResource
    {
        return new WebhookSubscriptionResource($set($webhook, true));
    }

    /** Disable a webhook subscription. */
    public function disable(WebhookSubscription $webhook, SetWebhookActive $set): WebhookSubscriptionResource
    {
        return new WebhookSubscriptionResource($set($webhook, false));
    }

    /**
     * Rotate the signing secret.
     *
     * Replace the signing secret. The response carries the new `secret` once; the old one keeps
     * signing (as a second `v1=` entry) for 24 hours.
     */
    public function rotateSecret(WebhookSubscription $webhook, RotateWebhookSecret $rotate): WebhookSubscriptionWithSecretResource
    {
        return new WebhookSubscriptionWithSecretResource($rotate($webhook));
    }

    /**
     * Send a test delivery.
     *
     * Queue a `ping` delivery to the subscription (also when it is disabled). Answers 202 with the
     * delivery; follow it in the delivery log.
     */
    #[Response(status: 202, type: WebhookDeliveryResource::class)]
    public function test(WebhookSubscription $webhook, QueueWebhookEvent $queue): JsonResponse
    {
        $delivery = $queue(WebhookEventType::Ping, ['message' => 'Test delivery from Smart Helpdesk.'], $webhook)->firstOrFail();

        return (new WebhookDeliveryResource($delivery->refresh()))->response()->setStatusCode(202);
    }

    /**
     * The subscription's delivery log, newest first (cursor pagination).
     */
    #[QueryParameter('cursor', 'Opaque cursor from `meta.next_cursor` of the previous page.', type: 'string')]
    #[QueryParameter('per_page', 'Page size, 1 to 100 (default 25).', type: 'integer')]
    #[QueryParameter('filter[state]', 'Only deliveries in this state: pending, succeeded, failed or dead.', type: 'string')]
    public function deliveries(Request $request, WebhookSubscription $webhook): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'cursor' => ['sometimes', 'string', 'max:500'],
            'filter.state' => ['sometimes', 'string', Rule::enum(DeliveryState::class)],
        ]);

        $query = $webhook->deliveries()->orderByDesc('created_at')->orderByDesc('id');
        if (isset($validated['filter']['state'])) {
            $query->where('state', $validated['filter']['state']);
        }

        return WebhookDeliveryResource::collection($query->cursorPaginate((int) ($validated['per_page'] ?? 25)));
    }
}
