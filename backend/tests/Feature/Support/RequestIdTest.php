<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

it('keeps a well-formed request id from the proxy', function (): void {
    $this->getJson('/v1/ping', ['X-Request-Id' => '0f8fad5b-d9cb-469f-a165-70867728950e'])
        ->assertOk()
        ->assertHeader('X-Request-Id', '0f8fad5b-d9cb-469f-a165-70867728950e');

    expect(Context::get('request_id'))->toBe('0f8fad5b-d9cb-469f-a165-70867728950e');
});

it('replaces a missing or malformed request id with a ULID', function (?string $incoming): void {
    $headers = $incoming === null ? [] : ['X-Request-Id' => $incoming];

    $id = $this->getJson('/v1/ping', $headers)->assertOk()->headers->get('X-Request-Id');

    expect($id)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
})->with([
    'missing' => null,
    'too short' => 'abc',
    'injection' => "abc\ndef ghi jkl",
    'too long' => str_repeat('a', 65),
]);

it('logs one request.completed line with route, status and duration', function (): void {
    Log::spy();

    $this->getJson('/v1/ping')->assertOk();

    Log::shouldHaveReceived('info')->once()->withArgs(
        fn (string $message, array $context): bool => $message === 'request.completed'
            && $context['route'] === 'system.ping'
            && $context['status'] === 200
            && $context['method'] === 'GET'
            && is_int($context['duration_ms']),
    );
});

it('does not log container health probes', function (): void {
    Log::spy();

    $this->get('/up')->assertOk();

    Log::shouldNotHaveReceived('info');
});
