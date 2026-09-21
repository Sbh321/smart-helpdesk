<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Jobs;

use App\Modules\Integrations\Actions\RecordDeliveryOutcome;
use App\Modules\Integrations\Domain\Webhooks\DeliveryState;
use App\Modules\Integrations\Domain\Webhooks\WebhookEventType;
use App\Modules\Integrations\Domain\Webhooks\WebhookSigner;
use App\Modules\Integrations\Exceptions\WebhookUrlRejected;
use App\Modules\Integrations\Models\WebhookDelivery;
use App\Modules\Integrations\Webhooks\DeliveryAttempt;
use App\Modules\Integrations\Webhooks\UrlGuard;
use App\Support\Time\Clock;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Queue\InteractsWithQueue;
use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * One delivery attempt (docs/07-api/webhooks.md §Delivery): claim the delivery, re-run the SSRF
 * guard, sign, POST with a 10 s timeout and no redirects, and record the outcome.
 *
 * The job never retries itself (`$tries = 1`): a failed attempt stores `next_attempt_at` from the
 * retry schedule and `webhooks:retry-due` dispatches the next attempt when it is due, which keeps
 * the schedule on `Clock` time. The claim moves `next_attempt_at` a lease ahead, so a duplicate job
 * (a sweep re-dispatching a slow queue) finds nothing due and does nothing.
 */
final class DeliverWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const USER_AGENT = 'SmartHelpdesk-Webhooks/1.0';

    /** Seconds a claimed attempt stays owned by this job; above the job timeout. */
    public const LEASE_SECONDS = 60;

    public int $tries = 1;

    public int $timeout = 15;

    public function __construct(public readonly string $deliveryId, public readonly string $subscriptionId)
    {
        $this->onQueue('webhooks');
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['webhooks', 'tenant:'.tenant()?->getTenantKey(), 'webhook:'.$this->subscriptionId];
    }

    public function handle(Clock $clock, UrlGuard $guard, Http $http, RecordDeliveryOutcome $record): void
    {
        $now = $clock->now();
        $claimed = WebhookDelivery::query()
            ->whereKey($this->deliveryId)
            ->where('state', DeliveryState::Pending->value)
            ->where('next_attempt_at', '<=', $now)
            ->update(['next_attempt_at' => $now->addSeconds(self::LEASE_SECONDS), 'updated_at' => $now]);
        if ($claimed !== 1) {
            return;
        }

        $delivery = WebhookDelivery::query()->with('subscription')->findOrFail($this->deliveryId);
        $subscription = $delivery->subscription;

        if (! $subscription->is_active && $delivery->event_type !== WebhookEventType::Ping->value) {
            $record->abandon($delivery, 'subscription_disabled');

            return;
        }

        $started = hrtime(true);
        $attempt = $this->send($delivery, $guard, $http, $clock);
        $record($delivery, $attempt->withDuration((int) round((hrtime(true) - $started) / 1_000_000)));
    }

    private function send(WebhookDelivery $delivery, UrlGuard $guard, Http $http, Clock $clock): DeliveryAttempt
    {
        $subscription = $delivery->subscription;

        try {
            $target = $guard->check($subscription->url);
        } catch (WebhookUrlRejected $rejected) {
            return DeliveryAttempt::failed('url_rejected: '.$rejected->reason);
        }

        $body = (string) json_encode($delivery->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = $clock->now()->getTimestamp();
        $options = ['stream' => true];
        if (($resolve = $target->curlResolve()) !== null) {
            $options['curl'] = [CURLOPT_RESOLVE => [$resolve]];
        }

        try {
            $response = $http
                ->withOptions($options)
                ->withoutRedirecting()
                ->timeout((int) config('helpdesk.webhooks.timeout_seconds', 10))
                ->connectTimeout((int) config('helpdesk.webhooks.connect_timeout_seconds', 5))
                ->withUserAgent(self::USER_AGENT)
                ->withHeaders([
                    'X-Helpdesk-Signature' => WebhookSigner::header($subscription->signingSecrets($clock->now()), $timestamp, $body),
                    'X-Helpdesk-Timestamp' => (string) $timestamp,
                    'X-Helpdesk-Event-Id' => $delivery->event_id,
                    'X-Helpdesk-Event-Type' => $delivery->event_type,
                    'X-Helpdesk-Delivery-Id' => $delivery->id,
                ])
                ->withBody($body, 'application/json')
                ->post($target->url);
        } catch (ConnectionException $exception) {
            return DeliveryAttempt::failed(str_contains(strtolower($exception->getMessage()), 'timed out') ? 'timeout' : 'connection_failed');
        } catch (Throwable $exception) {
            report($exception);

            return DeliveryAttempt::failed('request_failed');
        }

        $excerpt = $this->excerpt($response->toPsrResponse()->getBody());
        $status = $response->status();

        return match (true) {
            $status >= 200 && $status < 300 => DeliveryAttempt::succeeded($status, $excerpt),
            $status >= 300 && $status < 400 => DeliveryAttempt::failed('redirect_not_followed', $status, $excerpt),
            default => DeliveryAttempt::failed('http_status', $status, $excerpt),
        };
    }

    /** The first kilobyte of the response body, as valid UTF-8; the rest is never read. */
    private function excerpt(StreamInterface $body): ?string
    {
        try {
            $bytes = (int) config('helpdesk.webhooks.response_excerpt_bytes', 1024);
            $read = '';
            while (! $body->eof() && strlen($read) < $bytes) {
                $chunk = $body->read($bytes - strlen($read));
                if ($chunk === '') {
                    break;
                }
                $read .= $chunk;
            }
            $body->close();
        } catch (Throwable) {
            return null;
        }

        $text = mb_scrub($read, 'UTF-8');

        return $text === '' ? null : mb_strcut($text, 0, $bytes, 'UTF-8');
    }
}
