<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Webhooks;

use App\Modules\Integrations\Exceptions\WebhookUrlRejected;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;

/**
 * SSRF guard for webhook URLs (docs/07-api/webhooks.md §SSRF protection, docs/03-architecture/security.md).
 * Runs when a subscription is created or its URL changes, and again right before every send.
 *
 * 1. absolute `https` URL with a host name, no user info, port 443 or 8443 (configurable);
 * 2. not one of the platform's own hosts;
 * 3. every A/AAAA address public ({@see IpAddressPolicy}); an IP literal is checked the same way.
 *
 * Host names in `helpdesk.webhooks.dev_allowed_hosts` skip the scheme, port and address checks so
 * the Compose `webhook-echo` service can be used in development. The list is ignored in production
 * and holds host names only, so it can never open a private range in general.
 */
final readonly class UrlGuard
{
    public function __construct(
        private DnsResolver $dns,
        private Config $config,
        private Application $app,
    ) {}

    /**
     * @throws WebhookUrlRejected
     */
    public function check(string $url): ResolvedTarget
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            throw WebhookUrlRejected::because('invalid_url');
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower(rtrim($parts['host'], '.'));
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw WebhookUrlRejected::because('scheme');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw WebhookUrlRejected::because('userinfo');
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $literal = trim($host, '[]');

        if ($this->isDevAllowListed($host)) {
            return new ResolvedTarget($url, $host, $port, [], true);
        }

        if ($scheme !== 'https') {
            throw WebhookUrlRejected::because('scheme');
        }
        /** @var list<int> $ports */
        $ports = (array) $this->config->get('helpdesk.webhooks.allowed_ports', [443]);
        if (! in_array($port, $ports, true)) {
            throw WebhookUrlRejected::because('port');
        }
        if ($this->isPlatformHost($host)) {
            throw WebhookUrlRejected::because('platform_host');
        }

        $addresses = filter_var($literal, FILTER_VALIDATE_IP) !== false ? [$literal] : $this->dns->resolve($host);
        if ($addresses === []) {
            throw WebhookUrlRejected::because('unresolvable');
        }
        foreach ($addresses as $address) {
            if (! IpAddressPolicy::isPublic($address)) {
                throw WebhookUrlRejected::because('private_address');
            }
        }

        return new ResolvedTarget($url, $host, $port, $addresses, false);
    }

    public function isDevAllowListed(string $host): bool
    {
        if ($this->app->environment('production')) {
            return false;
        }

        /** @var list<string> $hosts */
        $hosts = (array) $this->config->get('helpdesk.webhooks.dev_allowed_hosts', []);

        return in_array(strtolower($host), array_map('strtolower', $hosts), true);
    }

    private function isPlatformHost(string $host): bool
    {
        $platform = strtolower((string) $this->config->get('helpdesk.platform_domain'));
        if ($platform !== '' && ($host === $platform || str_ends_with($host, '.'.$platform))) {
            return true;
        }

        /** @var array<string, string> $hosts */
        $hosts = (array) $this->config->get('helpdesk.hosts', []);

        return in_array($host, array_map('strtolower', $hosts), true)
            || in_array($host, ['localhost', 'localhost.localdomain'], true)
            || str_ends_with($host, '.localhost');
    }
}
