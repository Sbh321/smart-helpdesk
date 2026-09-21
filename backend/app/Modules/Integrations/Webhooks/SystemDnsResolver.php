<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Webhooks;

/**
 * DNS through the operating system: A and AAAA records, falling back to the hosts file and the
 * resolver library (`gethostbynamel`) for names such as Compose service names.
 */
final class SystemDnsResolver implements DnsResolver
{
    public function resolve(string $host): array
    {
        $addresses = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        foreach (is_array($records) ? $records : [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip)) {
                $addresses[] = $ip;
            }
        }

        if ($addresses === []) {
            $fallback = @gethostbynamel($host);
            $addresses = is_array($fallback) ? $fallback : [];
        }

        return array_values(array_unique($addresses));
    }
}
