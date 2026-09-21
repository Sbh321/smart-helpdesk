<?php

declare(strict_types=1);

use App\Modules\Integrations\Exceptions\WebhookUrlRejected;
use App\Modules\Integrations\Webhooks\UrlGuard;

require_once __DIR__.'/WebhookTestHelpers.php';

/*
 * The SSRF guard (docs/07-api/webhooks.md §SSRF protection) with DNS answers set by the test.
 */

beforeEach(function (): void {
    fakeWebhookDns([
        'internal.example.com' => ['10.0.0.5'],
        'mixed.example.com' => ['93.184.215.14', '192.168.1.20'],
        'metadata.example.com' => ['169.254.169.254'],
        'v6-loopback.example.com' => ['::1'],
        'v6-ula.example.com' => ['fd00::1'],
        'webhook-echo' => ['172.18.0.9'],
    ]);
    config(['helpdesk.webhooks.dev_allowed_hosts' => []]);
});

function rejection(string $url): ?string
{
    try {
        app(UrlGuard::class)->check($url);

        return null;
    } catch (WebhookUrlRejected $rejected) {
        return $rejected->reason;
    }
}

it('accepts a public https URL and pins the first resolved address', function (): void {
    $target = app(UrlGuard::class)->check('https://hooks.example.com/incoming?x=1');

    expect($target->addresses)->toBe(['93.184.215.14'])
        ->and($target->port)->toBe(443)
        ->and($target->curlResolve())->toBe('hooks.example.com:443:93.184.215.14');

    $v6 = app(UrlGuard::class)->check('https://receiver.example.net:8443/hook');
    expect($v6->curlResolve())->toBe('receiver.example.net:8443:93.184.215.15');
});

it('rejects URLs by shape before resolving anything', function (string $url, string $reason): void {
    expect(rejection($url))->toBe($reason);
})->with([
    'plain http' => ['http://hooks.example.com/x', 'scheme'],
    'ftp' => ['ftp://hooks.example.com/x', 'scheme'],
    'no host' => ['https:///path', 'invalid_url'],
    'relative' => ['/just/a/path', 'invalid_url'],
    'user info' => ['https://user:pass@hooks.example.com/x', 'userinfo'],
    'odd port' => ['https://hooks.example.com:22/x', 'port'],
    'the platform itself' => ['https://api.shp.localhost/v1/ping', 'platform_host'],
    'localhost' => ['https://localhost/x', 'platform_host'],
    'unresolvable' => ['https://nowhere.example.org/x', 'unresolvable'],
]);

it('rejects hosts that resolve to private, loopback, link-local or metadata addresses', function (string $url): void {
    expect(rejection($url))->toBe('private_address');
})->with([
    'rfc1918' => 'https://internal.example.com/x',
    'one private record among public ones' => 'https://mixed.example.com/x',
    'cloud metadata' => 'https://metadata.example.com/latest',
    'ipv6 loopback' => 'https://v6-loopback.example.com/x',
    'ipv6 unique local' => 'https://v6-ula.example.com/x',
    'ipv4 literal' => 'https://127.0.0.1/x',
    'metadata literal' => 'https://169.254.169.254/latest/meta-data',
    'ipv6 literal' => 'https://[::1]/x',
    'v4-mapped ipv6 literal' => 'https://[::ffff:10.0.0.1]/x',
]);

it('lets a development allow-listed host through, only outside production', function (): void {
    config(['helpdesk.webhooks.dev_allowed_hosts' => ['webhook-echo']]);

    $target = app(UrlGuard::class)->check('http://webhook-echo:9100/hooks');
    expect($target->devAllowListed)->toBeTrue()
        ->and($target->curlResolve())->toBeNull()
        // The allow-list names hosts; it never opens private addresses in general.
        ->and(rejection('http://172.18.0.9:9100/hooks'))->toBe('scheme')
        ->and(rejection('https://172.18.0.9/hooks'))->toBe('private_address');

    app()->detectEnvironment(fn (): string => 'production');
    expect(rejection('http://webhook-echo:9100/hooks'))->toBe('scheme');
});
