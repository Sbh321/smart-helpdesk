<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Integrations\Domain\Webhooks\DeliveryState;
use App\Modules\Integrations\Domain\Webhooks\RetrySchedule;
use App\Modules\Integrations\Domain\Webhooks\WebhookSigner;
use App\Modules\Integrations\Jobs\DeliverWebhook;
use App\Modules\Integrations\Models\WebhookDelivery;
use App\Modules\Integrations\Models\WebhookSubscription;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/WebhookTestHelpers.php';

/*
 * The DeliverWebhook job, the retry sweep and retention (docs/07-api/webhooks.md §Delivery,
 * docs/11-operations/scheduler.md). The clock is frozen and jitter is off, so the schedule is exact.
 */

beforeEach(function (): void {
    $this->clock = new FrozenClock('2026-09-21 10:00:00');
    $this->app->instance(Clock::class, $this->clock);
    $this->app->instance(RetrySchedule::class, new RetrySchedule(0.0));
    $this->dns = fakeWebhookDns();
    $this->acme = createTenant('acme');
    $this->webhook = createWebhook($this->acme, attributes: ['url' => 'https://hooks.example.com/in']);
    // The assertions read acme's rows, which row-level security shows only inside acme.
    tenancy()->initialize($this->acme);
});

function pendingDelivery(WebhookSubscription $webhook, array $attributes = []): WebhookDelivery
{
    return WebhookDelivery::factory()->forSubscription($webhook)->create([
        'next_attempt_at' => test()->clock->now(),
        ...$attributes,
    ]);
}

function runDelivery(WebhookDelivery $delivery): WebhookDelivery
{
    // Called directly rather than dispatched, so the job also runs while Queue::fake() is active.
    test()->acme->run(fn () => app()->call([new DeliverWebhook($delivery->id, $delivery->subscription_id), 'handle']));

    return $delivery->refresh();
}

it('posts the exact payload with every documented header and a signature the receiver can verify', function (): void {
    Http::fake(['hooks.example.com/*' => Http::response(str_repeat('é', 1500), 202)]);
    $delivery = pendingDelivery($this->webhook);

    runDelivery($delivery);

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) use ($delivery): bool {
        $ts = (int) $request->header('X-Helpdesk-Timestamp')[0];

        return $request->url() === 'https://hooks.example.com/in'
            && $request->method() === 'POST'
            && $request->header('Content-Type')[0] === 'application/json'
            && $request->header('User-Agent')[0] === 'SmartHelpdesk-Webhooks/1.0'
            && $request->header('X-Helpdesk-Event-Id')[0] === $delivery->event_id
            && $request->header('X-Helpdesk-Event-Type')[0] === 'ticket.created'
            && $request->header('X-Helpdesk-Delivery-Id')[0] === $delivery->id
            && $ts === $this->clock->now()->getTimestamp()
            && $request->body() === json_encode($delivery->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            && WebhookSigner::verify($request->body(), $request->header('X-Helpdesk-Signature')[0], $ts, $this->webhook->secret, $ts);
    });

    expect($delivery->state)->toBe(DeliveryState::Succeeded)
        ->and($delivery->attempt)->toBe(1)
        ->and($delivery->response_status)->toBe(202)
        ->and(strlen((string) $delivery->response_excerpt))->toBeLessThanOrEqual(1024)->toBeGreaterThan(1000)
        ->and(mb_check_encoding((string) $delivery->response_excerpt, 'UTF-8'))->toBeTrue()
        ->and($delivery->next_attempt_at)->toBeNull()
        ->and($delivery->last_attempted_at?->toIso8601ZuluString())->toBe('2026-09-21T10:00:00Z')
        ->and($this->webhook->refresh()->last_delivery_at?->toIso8601ZuluString())->toBe('2026-09-21T10:00:00Z');
});

it('follows the retry schedule 1 m, 5 m, 30 m, 2 h, 12 h and then marks the delivery dead', function (): void {
    Http::fake(['hooks.example.com/*' => Http::response('down', 503)]);
    Queue::fake();
    $delivery = pendingDelivery($this->webhook);

    $expected = ['2026-09-21T10:01:00Z', '2026-09-21T10:06:00Z', '2026-09-21T10:36:00Z', '2026-09-21T12:36:00Z', '2026-09-22T00:36:00Z'];
    foreach ($expected as $index => $next) {
        runDelivery($delivery);
        expect($delivery->state)->toBe(DeliveryState::Failed)
            ->and($delivery->attempt)->toBe($index + 1)
            ->and($delivery->response_status)->toBe(503)
            ->and($delivery->error)->toBe('http_status')
            ->and($delivery->next_attempt_at?->toIso8601ZuluString())->toBe($next);

        // One second early the sweep queues nothing; at the due time it queues this delivery.
        $this->clock->set($delivery->next_attempt_at->subSecond());
        $this->artisan('webhooks:retry-due')->assertSuccessful();
        Queue::assertPushed(DeliverWebhook::class, $index);

        $this->clock->set($delivery->next_attempt_at);
        $this->artisan('webhooks:retry-due')->assertSuccessful();
        Queue::assertPushed(DeliverWebhook::class, $index + 1);
        Queue::assertPushedOn('webhooks', DeliverWebhook::class);
        expect($delivery->refresh()->state)->toBe(DeliveryState::Pending);
    }

    runDelivery($delivery);
    expect($delivery->state)->toBe(DeliveryState::Dead)
        ->and($delivery->attempt)->toBe(6)
        ->and($delivery->next_attempt_at)->toBeNull();

    $this->clock->advance('1 day');
    $this->artisan('webhooks:retry-due')->assertSuccessful();
    Queue::assertPushed(DeliverWebhook::class, 5);
    Http::assertSentCount(6);
});

it('disables the subscription after 20 consecutive failed deliveries, and a success resets the count', function (): void {
    Http::fake(['hooks.example.com/*' => Http::sequence()->push('ok', 200)->push('no', 500)->push('no', 500)]);
    Queue::fake();
    $this->webhook->forceFill(['consecutive_failures' => 18])->save();

    runDelivery(pendingDelivery($this->webhook));
    expect($this->webhook->refresh()->consecutive_failures)->toBe(0)->and($this->webhook->is_active)->toBeTrue();

    $this->webhook->forceFill(['consecutive_failures' => 18])->save();
    runDelivery(pendingDelivery($this->webhook));
    expect($this->webhook->refresh()->consecutive_failures)->toBe(19)->and($this->webhook->is_active)->toBeTrue();

    runDelivery(pendingDelivery($this->webhook));
    $this->webhook->refresh();
    expect($this->webhook->consecutive_failures)->toBe(20)
        ->and($this->webhook->is_active)->toBeFalse()
        ->and($this->webhook->disabled_reason)->toBe('consecutive_failures')
        ->and($this->webhook->disabled_at?->toIso8601ZuluString())->toBe('2026-09-21T10:00:00Z');

    $audit = AuditLog::query()->withoutGlobalScopes()->where('action', 'webhook.disabled')->sole();
    expect($audit->actor_type->value ?? $audit->actor_type)->toBe('system')
        ->and($audit->changes['reason'])->toBe('consecutive_failures');
});

it('does not send deliveries of a disabled subscription and marks them dead', function (): void {
    Http::fake();
    $this->webhook->forceFill(['is_active' => false])->save();

    $delivery = runDelivery(pendingDelivery($this->webhook));

    Http::assertNothingSent();
    expect($delivery->state)->toBe(DeliveryState::Dead)->and($delivery->error)->toBe('subscription_disabled');
});

it('does not follow redirects and counts them as failures', function (): void {
    Http::fake([
        'hooks.example.com/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data']),
        '169.254.169.254/*' => Http::response('secret', 200),
    ]);

    $delivery = runDelivery(pendingDelivery($this->webhook));

    Http::assertSentCount(1);
    expect($delivery->state)->toBe(DeliveryState::Failed)
        ->and($delivery->response_status)->toBe(302)
        ->and($delivery->error)->toBe('redirect_not_followed');
});

it('re-checks the URL at delivery time and refuses when DNS now points at a private address', function (): void {
    Http::fake();
    $this->dns->answers['hooks.example.com'] = ['10.0.0.12'];

    $delivery = runDelivery(pendingDelivery($this->webhook));

    Http::assertNothingSent();
    expect($delivery->state)->toBe(DeliveryState::Failed)
        ->and($delivery->error)->toBe('url_rejected: private_address')
        ->and($delivery->next_attempt_at?->toIso8601ZuluString())->toBe('2026-09-21T10:01:00Z');
});

it('records a timeout and a connection failure', function (string $message, string $error): void {
    Http::fake(fn () => throw new ConnectionException($message));

    $delivery = runDelivery(pendingDelivery($this->webhook));

    expect($delivery->state)->toBe(DeliveryState::Failed)->and($delivery->error)->toBe($error)->and($delivery->response_status)->toBeNull();
})->with([
    'timeout' => ['cURL error 28: Operation timed out after 10001 milliseconds', 'timeout'],
    'refused' => ['cURL error 7: Failed to connect', 'connection_failed'],
]);

it('sends with a 10 second timeout, no redirects and the connection pinned to the checked address', function (): void {
    $seen = null;
    Http::fake(function (Request $request, array $options) use (&$seen) {
        $seen = $options;

        return Http::response('ok');
    });

    runDelivery(pendingDelivery($this->webhook));

    expect($seen['timeout'] ?? null)->toBe(10)
        ->and($seen['connect_timeout'] ?? null)->toBe(5)
        ->and($seen['allow_redirects'] ?? null)->toBeFalse()
        ->and($seen['curl'][CURLOPT_RESOLVE] ?? null)->toBe(['hooks.example.com:443:93.184.215.14']);
});

it('ignores a duplicate job for a delivery that is already claimed or finished', function (): void {
    Http::fake(['hooks.example.com/*' => Http::response('ok')]);
    $delivery = pendingDelivery($this->webhook);

    runDelivery($delivery);
    runDelivery($delivery);

    Http::assertSentCount(1);
    expect($delivery->attempt)->toBe(1);
});

it('does not retry a failed test ping automatically', function (): void {
    Http::fake(['hooks.example.com/*' => Http::response('no', 500)]);

    $delivery = runDelivery(pendingDelivery($this->webhook, ['event_type' => 'ping']));

    expect($delivery->state)->toBe(DeliveryState::Dead)->and($delivery->next_attempt_at)->toBeNull();
});

it('re-queues a pending delivery whose job was lost once its lease is five minutes old', function (): void {
    Queue::fake();
    $delivery = pendingDelivery($this->webhook);

    $this->clock->advance('4 minutes');
    $this->artisan('webhooks:retry-due');
    Queue::assertNothingPushed();

    $this->clock->advance('1 minute');
    $this->artisan('webhooks:retry-due');
    Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job): bool => $job->deliveryId === $delivery->id);
});

it('prunes deliveries older than 30 days in every workspace', function (): void {
    $globex = createTenant('globex');
    $old = pendingDelivery($this->webhook, ['created_at' => '2026-08-22 09:59:00']);
    $foreignOld = WebhookDelivery::factory()->forSubscription(createWebhook($globex))->create(['created_at' => '2026-08-01 00:00:00']);
    $kept = pendingDelivery($this->webhook, ['created_at' => '2026-08-22 10:01:00']);

    tenancy()->end();
    $this->artisan('webhooks:prune')->assertSuccessful();

    expect($this->acme->run(fn (): array => WebhookDelivery::query()->pluck('id')->all()))->toBe([$kept->id])
        ->and($globex->run(fn (): bool => WebhookDelivery::query()->whereKey($foreignOld->id)->exists()))->toBeFalse();
});
