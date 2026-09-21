<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Integrations\Domain\Webhooks\DeliveryState;
use App\Modules\Integrations\Domain\Webhooks\WebhookSigner;
use App\Modules\Integrations\Jobs\DeliverWebhook;
use App\Modules\Integrations\Models\WebhookDelivery;
use App\Modules\Integrations\Models\WebhookSubscription;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/IntegrationTestHelpers.php';
require_once __DIR__.'/WebhookTestHelpers.php';

/*
 * /v1/webhooks and /v1/webhook-deliveries (docs/07-api/webhooks.md, conventions.md §Endpoint
 * inventory): CRUD, secret shown once, test delivery, delivery log, manual retry, permissions.
 */

beforeEach(function (): void {
    $this->clock = new FrozenClock('2026-09-21 10:00:00');
    $this->app->instance(Clock::class, $this->clock);
    $this->acme = createTenant('acme');
    $this->globex = createTenant('globex');
    fakeWebhookDns(['internal.example.com' => ['192.168.10.4']]);
});

it('creates a subscription and shows its secret exactly once', function (): void {
    $admin = actingAsRole($this->acme, 'admin');

    $response = $this->postJson('/v1/webhooks', [
        'name' => 'CRM sync',
        'url' => 'https://hooks.example.com/helpdesk',
        'events' => ['ticket.created', 'ticket.resolved'],
    ])->assertCreated()
        ->assertJsonPath('data.name', 'CRM sync')
        ->assertJsonPath('data.events', ['ticket.created', 'ticket.resolved'])
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.api_version', 'v1');

    $secret = $response->json('data.secret');
    $subscription = WebhookSubscription::query()->withoutGlobalScopes()->findOrFail($response->json('data.id'));
    $stored = DB::table('webhook_subscriptions')->where('id', $subscription->id)->value('secret');

    expect(strlen((string) base64_decode((string) $secret, true)))->toBe(32)
        ->and($subscription->tenant_id)->toBe($this->acme->id)
        ->and($subscription->created_by_user_id)->toBe($admin->id)
        ->and($subscription->secret)->toBe($secret)
        // Encrypted at rest: the column holds ciphertext, not the secret.
        ->and($stored)->not->toBe($secret)->not->toContain($secret);

    $this->getJson('/v1/webhooks')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.secret');
    $this->getJson("/v1/webhooks/{$subscription->id}")->assertOk()->assertJsonMissingPath('data.secret');

    $audit = AuditLog::query()->where('action', 'webhook.created')->sole();
    expect($audit->actor_id)->toBe($admin->id)
        ->and(json_encode($audit->changes))->not->toContain((string) $secret);
});

it('validates the name, URL and events', function (): void {
    actingAsRole($this->acme, 'admin');

    $this->postJson('/v1/webhooks', ['name' => '', 'url' => 'not a url', 'events' => ['ticket.created', 'ping', '*']])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonStructure(['errors' => ['name', 'url', 'events.1', 'events.2']]);

    $this->postJson('/v1/webhooks', ['name' => 'x', 'url' => 'https://hooks.example.com', 'events' => []])->assertUnprocessable();
});

it('rejects a private-network URL with 422 webhook_url_rejected at subscription time', function (string $url, string $reason): void {
    actingAsRole($this->acme, 'admin');

    $this->postJson('/v1/webhooks', ['name' => 'Internal', 'url' => $url, 'events' => ['ticket.created']])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'webhook_url_rejected')
        ->assertJsonPath('meta.reason', $reason)
        ->assertJsonStructure(['errors' => ['url']]);

    expect(WebhookSubscription::query()->withoutGlobalScopes()->count())->toBe(0);
})->with([
    'private ipv4 literal' => ['https://10.0.0.8/hook', 'private_address'],
    'metadata address' => ['https://169.254.169.254/latest', 'private_address'],
    'ipv6 loopback' => ['https://[::1]/hook', 'private_address'],
    'dns to a private address' => ['https://internal.example.com/hook', 'private_address'],
    'plain http' => ['http://hooks.example.com/hook', 'scheme'],
]);

it('edits a subscription, re-checking only a changed URL, and audits the change', function (): void {
    actingAsRole($this->acme, 'admin');
    $webhook = createWebhook($this->acme, attributes: ['url' => 'https://hooks.example.com/a']);

    $this->patchJson("/v1/webhooks/{$webhook->id}", ['name' => 'Renamed', 'events' => ['contact.created']])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed')
        ->assertJsonPath('data.events', ['contact.created']);

    $this->patchJson("/v1/webhooks/{$webhook->id}", ['url' => 'https://internal.example.com/a'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'webhook_url_rejected');

    $audit = AuditLog::query()->where('action', 'webhook.updated')->sole();
    expect($audit->changes['name'])->toEqual(['old' => $webhook->name, 'new' => 'Renamed'])
        ->and($webhook->refresh()->url)->toBe('https://hooks.example.com/a');
});

it('disables and enables a subscription, clearing the failure counter', function (): void {
    actingAsRole($this->acme, 'admin');
    $webhook = createWebhook($this->acme, attributes: ['consecutive_failures' => 7]);

    $this->postJson("/v1/webhooks/{$webhook->id}/disable")
        ->assertOk()
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.disabled_reason', 'manual')
        ->assertJsonPath('data.disabled_at', '2026-09-21T10:00:00Z');

    $this->postJson("/v1/webhooks/{$webhook->id}/enable")
        ->assertOk()
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.disabled_reason', null)
        ->assertJsonPath('data.consecutive_failures', 0);

    expect(AuditLog::query()->whereIn('action', ['webhook.disabled', 'webhook.enabled'])->pluck('action')->sort()->values()->all())
        ->toBe(['webhook.disabled', 'webhook.enabled']);
});

it('rotates the secret, shows the new one once and keeps the old one for 24 hours', function (): void {
    actingAsRole($this->acme, 'admin');
    $webhook = createWebhook($this->acme);
    $old = $webhook->secret;

    $response = $this->postJson("/v1/webhooks/{$webhook->id}/rotate-secret")
        ->assertOk()
        ->assertJsonPath('data.previous_secret_expires_at', '2026-09-22T10:00:00Z');
    $new = $response->json('data.secret');
    $webhook->refresh();

    expect($new)->not->toBe($old)
        ->and($webhook->signingSecrets($this->clock->now()))->toBe([$new, $old])
        ->and($webhook->signingSecrets($this->clock->now()->addHours(25)))->toBe([$new])
        ->and(AuditLog::query()->where('action', 'webhook.secret_rotated')->count())->toBe(1);
});

it('deletes a subscription with its deliveries', function (): void {
    actingAsRole($this->acme, 'admin');
    $webhook = createWebhook($this->acme);
    WebhookDelivery::factory()->forSubscription($webhook)->create();

    $this->deleteJson("/v1/webhooks/{$webhook->id}")->assertNoContent();

    expect(WebhookSubscription::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(WebhookDelivery::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'webhook.deleted')->count())->toBe(1);
});

it('lists the event catalogue without ping', function (): void {
    actingAsRole($this->acme, 'developer');

    $types = $this->getJson('/v1/webhooks/events')->assertOk()->json('data.*.type');

    expect($types)->toContain('ticket.created', 'ticket.sla_breached', 'contact.updated')
        ->not->toContain('ping')
        ->toHaveCount(11);
});

it('queues a signed ping on test and answers 202 with the delivery', function (): void {
    actingAsRole($this->acme, 'admin');
    $webhook = createWebhook($this->acme);
    Http::fake(['hooks.example.com/*' => Http::response('pong', 200)]);

    $response = $this->postJson("/v1/webhooks/{$webhook->id}/test")
        ->assertAccepted()
        ->assertJsonPath('data.event_type', 'ping')
        ->assertJsonPath('data.subscription_id', $webhook->id);

    // The queue is synchronous in tests, so the attempt already ran.
    $delivery = WebhookDelivery::query()->findOrFail($response->json('data.id'));
    expect($delivery->state)->toBe(DeliveryState::Succeeded)
        ->and($delivery->payload['data'])->toBe(['message' => 'Test delivery from Smart Helpdesk.']);

    Http::assertSent(fn ($request): bool => $request->hasHeader('X-Helpdesk-Event-Type', 'ping')
        && WebhookSigner::verify($request->body(), $request->header('X-Helpdesk-Signature')[0], (int) $request->header('X-Helpdesk-Timestamp')[0], $webhook->secret, $this->clock->now()->getTimestamp()));
});

it('lists the delivery log newest first with a cursor and a state filter, and shows one delivery with its payload', function (): void {
    actingAsRole($this->acme, 'admin');
    $webhook = createWebhook($this->acme);
    $other = createWebhook($this->acme);
    $old = WebhookDelivery::factory()->forSubscription($webhook)->inState(DeliveryState::Succeeded)->create(['created_at' => '2026-09-21 08:00:00']);
    $new = WebhookDelivery::factory()->forSubscription($webhook)->inState(DeliveryState::Dead)->create(['created_at' => '2026-09-21 09:00:00', 'attempt' => 6, 'response_status' => 500]);
    WebhookDelivery::factory()->forSubscription($other)->create();

    $page = $this->getJson("/v1/webhooks/{$webhook->id}/deliveries?per_page=1")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $new->id)
        ->assertJsonPath('data.0.state', 'dead')
        ->assertJsonPath('data.0.attempt', 6)
        ->assertJsonPath('data.0.response_status', 500)
        ->assertJsonMissingPath('data.0.payload');

    $this->getJson("/v1/webhooks/{$webhook->id}/deliveries?per_page=1&cursor=".$page->json('meta.next_cursor'))
        ->assertOk()
        ->assertJsonPath('data.0.id', $old->id);

    $this->getJson("/v1/webhooks/{$webhook->id}/deliveries?filter[state]=succeeded")
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $old->id);
    $this->getJson("/v1/webhooks/{$webhook->id}/deliveries?filter[state]=bogus")->assertUnprocessable();

    $this->getJson("/v1/webhook-deliveries/{$new->id}")
        ->assertOk()
        ->assertJsonPath('data.payload.id', $new->event_id)
        ->assertJsonPath('data.payload.type', 'ticket.created');
});

it('retries a dead delivery by hand with a fresh schedule, at most five times', function (): void {
    actingAsRole($this->acme, 'admin');
    $webhook = createWebhook($this->acme);
    $delivery = WebhookDelivery::factory()->forSubscription($webhook)->inState(DeliveryState::Dead)
        ->create(['attempt' => 6, 'sequence_attempt' => 6]);
    Queue::fake();

    $this->postJson("/v1/webhook-deliveries/{$delivery->id}/retry")
        ->assertAccepted()
        ->assertJsonPath('data.state', 'pending')
        ->assertJsonPath('data.manual_retries', 1)
        ->assertJsonPath('data.attempt', 6);

    Queue::assertPushedOn('webhooks', DeliverWebhook::class, fn (DeliverWebhook $job): bool => $job->deliveryId === $delivery->id);
    expect($delivery->refresh()->sequence_attempt)->toBe(0);

    // Not failed or dead any more.
    $this->postJson("/v1/webhook-deliveries/{$delivery->id}/retry")
        ->assertStatus(409)->assertJsonPath('code', 'delivery_not_retryable')->assertJsonPath('meta.reason', 'state');

    $delivery->forceFill(['state' => DeliveryState::Dead, 'manual_retries' => 5])->save();
    $this->postJson("/v1/webhook-deliveries/{$delivery->id}/retry")
        ->assertStatus(409)->assertJsonPath('meta.reason', 'manual_retries_exhausted');

    $delivery->forceFill(['manual_retries' => 0])->save();
    $webhook->forceFill(['is_active' => false])->save();
    $this->postJson("/v1/webhook-deliveries/{$delivery->id}/retry")
        ->assertStatus(409)->assertJsonPath('meta.reason', 'subscription_disabled');
});

it('answers 404 for another workspace\'s subscription and delivery', function (): void {
    actingAsRole($this->acme, 'admin');
    $foreign = createWebhook($this->globex);
    $foreignDelivery = WebhookDelivery::factory()->forSubscription($foreign)->inState(DeliveryState::Dead)->create();

    $this->getJson("/v1/webhooks/{$foreign->id}")->assertNotFound();
    $this->patchJson("/v1/webhooks/{$foreign->id}", ['name' => 'x'])->assertNotFound();
    $this->postJson("/v1/webhooks/{$foreign->id}/test")->assertNotFound();
    $this->getJson("/v1/webhooks/{$foreign->id}/deliveries")->assertNotFound();
    $this->getJson("/v1/webhook-deliveries/{$foreignDelivery->id}")->assertNotFound();
    $this->postJson("/v1/webhook-deliveries/{$foreignDelivery->id}/retry")->assertNotFound();
    $this->getJson('/v1/webhooks')->assertOk()->assertJsonCount(0, 'data');
});

it('refuses users without integrations.manage', function (string $role): void {
    actingAsRole($this->acme, $role);
    $webhook = createWebhook($this->acme);

    $this->getJson('/v1/webhooks')->assertForbidden();
    $this->postJson('/v1/webhooks', ['name' => 'x', 'url' => 'https://hooks.example.com', 'events' => ['ticket.created']])->assertForbidden();
    $this->postJson("/v1/webhooks/{$webhook->id}/test")->assertForbidden();
})->with(['agent', 'manager']);

it('lets an API client with webhooks:manage manage webhooks, and nothing else', function (): void {
    $client = createApiClient($this->acme, ['webhooks:manage']);
    $token = issueToken($client, 'webhooks:manage');

    $id = $this->withToken($token)->postJson('/v1/webhooks', [
        'name' => 'From the API', 'url' => 'https://hooks.example.com/api', 'events' => ['ticket.created'],
    ])->assertCreated()->json('data.id');

    $this->withToken($token)->getJson('/v1/webhooks')->assertOk()->assertJsonPath('data.0.id', $id);
    $this->withToken($token)->getJson('/v1/api-clients')->assertForbidden();
    $this->withToken($token)->getJson('/v1/tickets')->assertForbidden();

    $audit = $this->acme->run(fn (): AuditLog => AuditLog::query()->where('action', 'webhook.created')->sole());
    expect($audit->actor_type->value ?? $audit->actor_type)->toBe('api_client')
        ->and($audit->actor_id)->toBe($client->id);

    // A client without the scope cannot reach the webhook routes.
    $reader = createApiClient($this->acme, ['tickets:read']);
    $this->withToken(issueToken($reader, 'tickets:read'))->getJson('/v1/webhooks')->assertForbidden();
});
