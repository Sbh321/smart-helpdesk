<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Webhooks;

/**
 * A webhook URL that passed the SSRF guard, with the addresses it was checked against. The sender
 * pins the connection to the first address (curl `CURLOPT_RESOLVE`), so a DNS change between the
 * check and the request cannot redirect it. Development hosts from the allow-list are not pinned.
 */
final readonly class ResolvedTarget
{
    /**
     * @param  list<string>  $addresses
     */
    public function __construct(
        public string $url,
        public string $host,
        public int $port,
        public array $addresses,
        public bool $devAllowListed,
    ) {}

    /**
     * The curl `--resolve` entry for the pinned address, or null when nothing is pinned.
     */
    public function curlResolve(): ?string
    {
        // An IP literal needs no DNS, so there is nothing to pin.
        if ($this->devAllowListed || $this->addresses === [] || filter_var(trim($this->host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        $ip = $this->addresses[0];

        return sprintf('%s:%d:%s', $this->host, $this->port, str_contains($ip, ':') ? "[{$ip}]" : $ip);
    }
}
