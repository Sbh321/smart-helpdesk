<?php

declare(strict_types=1);

use App\Modules\Integrations\Models\WebhookSubscription;
use App\Modules\Integrations\Webhooks\DnsResolver;
use App\Modules\Tenancy\Models\Tenant;

/**
 * DNS answers set by the test, so the SSRF guard runs without the network.
 */
final class FakeDnsResolver implements DnsResolver
{
    /** @param array<string, list<string>> $answers */
    public function __construct(public array $answers = []) {}

    public function resolve(string $host): array
    {
        return $this->answers[strtolower($host)] ?? [];
    }
}

/**
 * Binds a fake resolver where `hooks.example.com` resolves to a public documentation-free address.
 *
 * @param  array<string, list<string>>  $extra
 */
function fakeWebhookDns(array $extra = []): FakeDnsResolver
{
    $dns = new FakeDnsResolver([
        'hooks.example.com' => ['93.184.215.14'],
        'receiver.example.net' => ['93.184.215.15', '2606:2800:21f:cb07:6820:80da:af6b:8b2c'],
        ...$extra,
    ]);
    app()->instance(DnsResolver::class, $dns);

    return $dns;
}

/**
 * @param  list<string>  $events
 */
function createWebhook(Tenant $tenant, array $events = ['ticket.created', 'ticket.status_changed'], array $attributes = []): WebhookSubscription
{
    return WebhookSubscription::factory()->forTenant($tenant)->events($events)->create($attributes);
}
