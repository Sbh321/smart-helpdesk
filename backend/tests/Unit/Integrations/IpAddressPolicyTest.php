<?php

declare(strict_types=1);

use App\Modules\Integrations\Webhooks\IpAddressPolicy;

it('refuses non-public addresses', function (string $ip): void {
    expect(IpAddressPolicy::isPublic($ip))->toBeFalse();
})->with([
    'loopback v4' => '127.0.0.1',
    'loopback v4 range' => '127.10.0.1',
    'this network' => '0.0.0.0',
    'rfc1918 10/8' => '10.1.2.3',
    'rfc1918 172.16/12' => '172.20.0.5',
    'rfc1918 192.168/16' => '192.168.1.10',
    'carrier-grade nat' => '100.64.0.1',
    'link-local' => '169.254.10.20',
    'cloud metadata' => '169.254.169.254',
    'documentation' => '192.0.2.1',
    'benchmarking' => '198.18.0.1',
    'multicast v4' => '224.0.0.1',
    'broadcast' => '255.255.255.255',
    'loopback v6' => '::1',
    'unspecified v6' => '::',
    'unique local v6' => 'fd12:3456:789a::1',
    'aws metadata v6' => 'fd00:ec2::254',
    'link-local v6' => 'fe80::1',
    'multicast v6' => 'ff02::1',
    'documentation v6' => '2001:db8::1',
    'v4-mapped loopback' => '::ffff:127.0.0.1',
    'v4-mapped metadata' => '::ffff:169.254.169.254',
    'v4-mapped private' => '::ffff:10.0.0.1',
    'nat64 private' => '64:ff9b::a00:1',
    '6to4 of private' => '2002:c0a8:0101::1',
    'not an address' => 'example.com',
]);

it('allows public unicast addresses', function (string $ip): void {
    expect(IpAddressPolicy::isPublic($ip))->toBeTrue();
})->with([
    'public v4' => '93.184.215.14',
    'another public v4' => '8.8.8.8',
    'just outside 172.16/12' => '172.32.0.1',
    'public v6' => '2606:4700:4700::1111',
    'v4-mapped public' => '::ffff:8.8.8.8',
]);
