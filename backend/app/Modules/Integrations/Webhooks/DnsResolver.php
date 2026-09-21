<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Webhooks;

/**
 * Resolves a host name to every A and AAAA address. Bound to {@see SystemDnsResolver}; tests bind a
 * fake so the SSRF guard can be exercised without the network.
 */
interface DnsResolver
{
    /**
     * @return list<string> IPv4 and IPv6 addresses; empty when the name does not resolve
     */
    public function resolve(string $host): array;
}
