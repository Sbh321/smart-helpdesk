<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Domain\Webhooks;

/**
 * HMAC-SHA256 signing of webhook deliveries (docs/07-api/webhooks.md §Signing).
 *
 * The signed input is `"{timestamp}.{raw_body}"`; the header value is `v1=<hex>`, with one entry
 * per valid secret (two during a secret rotation), comma-separated. `verify()` is the PHP receiver
 * snippet of the same page, kept here so the tests can hold the snippet against the signer.
 */
final class WebhookSigner
{
    public const SCHEME = 'v1';

    public const TOLERANCE_SECONDS = 300;

    public static function signature(string $secret, int $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /**
     * @param  list<string>  $secrets  current secret first, then the previous one during rotation
     */
    public static function header(array $secrets, int $timestamp, string $body): string
    {
        return implode(',', array_map(
            fn (string $secret): string => self::SCHEME.'='.self::signature($secret, $timestamp, $body),
            $secrets,
        ));
    }

    /**
     * Receiver-side verification: any `v1=` entry matches and the timestamp is within the tolerance.
     */
    public static function verify(string $body, string $signatureHeader, int $timestamp, string $secret, int $now, int $tolerance = self::TOLERANCE_SECONDS): bool
    {
        if ($timestamp === 0 || abs($now - $timestamp) > $tolerance) {
            return false;
        }

        $expected = self::signature($secret, $timestamp, $body);
        foreach (explode(',', $signatureHeader) as $part) {
            $part = trim($part);
            if (str_starts_with($part, self::SCHEME.'=') && hash_equals($expected, substr($part, 3))) {
                return true;
            }
        }

        return false;
    }

    /** 32 random bytes, base64: shown once and stored with the `encrypted` cast. */
    public static function newSecret(): string
    {
        return base64_encode(random_bytes(32));
    }
}
