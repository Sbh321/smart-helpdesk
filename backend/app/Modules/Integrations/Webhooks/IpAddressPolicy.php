<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Webhooks;

/**
 * Which addresses a webhook may be sent to (docs/07-api/webhooks.md §SSRF protection): only public
 * unicast. Loopback, private (RFC 1918, ULA), carrier-grade NAT, link-local (incl. the cloud metadata
 * address 169.254.169.254), multicast, documentation and reserved ranges are refused, for IPv4 and
 * IPv6, including IPv4 addresses embedded in IPv6 (mapped, NAT64, 6to4).
 */
final class IpAddressPolicy
{
    /** @var list<string> */
    private const BLOCKED_V4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
    ];

    /** @var list<string> */
    private const BLOCKED_V6 = [
        '::/128', '::1/128', '100::/64', '2001::/23', '2001:db8::/32', 'fc00::/7', 'fe80::/10',
        'fec0::/10', 'ff00::/8',
    ];

    /** IPv6 prefixes that carry an IPv4 address, with the offset of those four bytes. */
    private const EMBEDDED_V4 = [
        ['::ffff:0:0/96', 12], // IPv4-mapped
        ['::/96', 12],         // IPv4-compatible (deprecated)
        ['64:ff9b::/96', 12],  // NAT64
        ['2002::/16', 2],      // 6to4
    ];

    public static function isPublic(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 4) {
            return ! self::inAny($packed, self::BLOCKED_V4);
        }

        foreach (self::EMBEDDED_V4 as [$cidr, $offset]) {
            if (self::inRange($packed, $cidr)) {
                $v4 = inet_ntop(substr($packed, $offset, 4));

                return $v4 !== false && self::isPublic($v4);
            }
        }

        return ! self::inAny($packed, self::BLOCKED_V6);
    }

    /**
     * @param  list<string>  $ranges
     */
    private static function inAny(string $packed, array $ranges): bool
    {
        foreach ($ranges as $cidr) {
            if (self::inRange($packed, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private static function inRange(string $packed, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr);
        $networkPacked = inet_pton($network);
        if ($networkPacked === false || strlen($networkPacked) !== strlen($packed)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($packed, 0, $bytes) !== substr($networkPacked, 0, $bytes)) {
            return false;
        }

        $remainder = $bits % 8;
        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($packed[$bytes]) & $mask) === (ord($networkPacked[$bytes]) & $mask);
    }
}
