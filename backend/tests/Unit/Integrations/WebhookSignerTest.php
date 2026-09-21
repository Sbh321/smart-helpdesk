<?php

declare(strict_types=1);

use App\Modules\Integrations\Domain\Webhooks\WebhookSigner;

/*
 * Signing and receiver verification (docs/07-api/webhooks.md §Signing). The PHP receiver snippet of
 * that page is copied below verbatim (renamed) and must accept what the signer produces.
 */

/**
 * The PHP snippet from docs/07-api/webhooks.md, unchanged except for its name.
 */
function docsVerify(string $rawBody, array $headers, string $secret, int $tolerance = 300): bool
{
    $ts = (int) ($headers['X-Helpdesk-Timestamp'] ?? 0);
    if ($ts === 0 || abs(time() - $ts) > $tolerance) {
        return false;
    }
    $expected = hash_hmac('sha256', $ts.'.'.$rawBody, $secret);
    foreach (explode(',', $headers['X-Helpdesk-Signature'] ?? '') as $part) {
        if (hash_equals($expected, substr(trim($part), 3))) {
            return true;
        }
    }

    return false;
}

const SIGNER_BODY = '{"id":"019a1f2f-5b7c-7e1d-8c3a-1a2b3c4d5e6f","type":"ping","data":{"message":"hi"}}';

it('signs "{timestamp}.{body}" with HMAC-SHA256 as a hex v1 entry', function (): void {
    $header = WebhookSigner::header(['secret'], 1_790_000_000, SIGNER_BODY);

    expect($header)->toBe('v1='.hash_hmac('sha256', '1790000000.'.SIGNER_BODY, 'secret'))
        ->and(WebhookSigner::signature('secret', 1_790_000_000, SIGNER_BODY))->toMatch('/^[0-9a-f]{64}$/');
});

it('matches a known test vector', function (): void {
    // Computed independently: printf '%s' '1700000000.{}' | openssl dgst -sha256 -hmac 'whsec'
    expect(WebhookSigner::signature('whsec', 1_700_000_000, '{}'))
        ->toBe('7d44587dddbaf4c7f70fef20f48cd594834ffea1641e3ac227b84408298738af');
});

it('sends one entry per secret during a rotation, current secret first', function (): void {
    $header = WebhookSigner::header(['new', 'old'], 1_790_000_000, SIGNER_BODY);
    $parts = explode(',', $header);

    expect($parts)->toHaveCount(2)
        ->and($parts[0])->toBe('v1='.WebhookSigner::signature('new', 1_790_000_000, SIGNER_BODY))
        ->and($parts[1])->toBe('v1='.WebhookSigner::signature('old', 1_790_000_000, SIGNER_BODY))
        ->and(WebhookSigner::verify(SIGNER_BODY, $header, 1_790_000_000, 'old', 1_790_000_010))->toBeTrue()
        ->and(WebhookSigner::verify(SIGNER_BODY, $header, 1_790_000_000, 'new', 1_790_000_010))->toBeTrue();
});

it('accepts a fresh delivery through the documented PHP snippet', function (): void {
    $ts = time();
    $headers = [
        'X-Helpdesk-Timestamp' => (string) $ts,
        'X-Helpdesk-Signature' => WebhookSigner::header(['s3cret', 'older'], $ts, SIGNER_BODY),
    ];

    expect(docsVerify(SIGNER_BODY, $headers, 's3cret'))->toBeTrue()
        ->and(docsVerify(SIGNER_BODY, $headers, 'older'))->toBeTrue()
        ->and(docsVerify(SIGNER_BODY, $headers, 'wrong'))->toBeFalse()
        ->and(docsVerify(SIGNER_BODY.' ', $headers, 's3cret'))->toBeFalse();
});

it('rejects a replay outside the five-minute window in the snippet and in the signer', function (): void {
    $old = time() - 301;
    $headers = ['X-Helpdesk-Timestamp' => (string) $old, 'X-Helpdesk-Signature' => WebhookSigner::header(['s3cret'], $old, SIGNER_BODY)];

    expect(docsVerify(SIGNER_BODY, $headers, 's3cret'))->toBeFalse()
        ->and(WebhookSigner::verify(SIGNER_BODY, $headers['X-Helpdesk-Signature'], $old, 's3cret', $old + 301))->toBeFalse()
        ->and(WebhookSigner::verify(SIGNER_BODY, $headers['X-Helpdesk-Signature'], $old, 's3cret', $old + 300))->toBeTrue()
        ->and(WebhookSigner::verify(SIGNER_BODY, $headers['X-Helpdesk-Signature'], $old, 's3cret', $old - 301))->toBeFalse();
});

it('rejects a tampered body, a missing timestamp and entries of another scheme', function (): void {
    $header = WebhookSigner::header(['s3cret'], 1_790_000_000, SIGNER_BODY);

    expect(WebhookSigner::verify(SIGNER_BODY.'x', $header, 1_790_000_000, 's3cret', 1_790_000_000))->toBeFalse()
        ->and(WebhookSigner::verify(SIGNER_BODY, $header, 0, 's3cret', 1_790_000_000))->toBeFalse()
        ->and(WebhookSigner::verify(SIGNER_BODY, str_replace('v1=', 'v0=', $header), 1_790_000_000, 's3cret', 1_790_000_000))->toBeFalse()
        // The signature covers the timestamp: the same body with another timestamp fails.
        ->and(WebhookSigner::verify(SIGNER_BODY, $header, 1_790_000_001, 's3cret', 1_790_000_001))->toBeFalse();
});

it('generates 32-byte base64 secrets that differ every time', function (): void {
    $a = WebhookSigner::newSecret();
    $b = WebhookSigner::newSecret();

    expect(strlen((string) base64_decode($a, true)))->toBe(32)
        ->and($a)->not->toBe($b);
});
