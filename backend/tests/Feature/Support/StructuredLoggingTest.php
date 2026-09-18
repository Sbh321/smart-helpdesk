<?php

declare(strict_types=1);

use App\Support\Logging\ContextProcessor;
use App\Support\Logging\RedactSecretsProcessor;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;

function jsonLogLine(Closure $write): array
{
    $path = tempnam(sys_get_temp_dir(), 'log');

    $logger = Log::build([
        'driver' => 'monolog',
        'handler' => StreamHandler::class,
        'handler_with' => ['stream' => $path],
        'formatter' => JsonFormatter::class,
        'processors' => [ContextProcessor::class, RedactSecretsProcessor::class],
    ]);

    $write($logger);
    $line = trim((string) file_get_contents($path));
    unlink($path);

    return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
}

it('writes JSON lines with request id, version and actor', function (): void {
    config(['helpdesk.version' => '2026.10.1']);
    Context::add('request_id', '01K6REQUEST0000000000000000');
    $this->actingAs(new GenericUser(['id' => '0199aaaa-0000-7000-8000-000000000001']));

    $record = jsonLogLine(fn ($logger) => $logger->info('ticket.created', ['number' => 1042]));

    expect($record['message'])->toBe('ticket.created')
        ->and($record['context'])->toBe(['number' => 1042])
        ->and($record['extra'])->toMatchArray([
            'request_id' => '01K6REQUEST0000000000000000',
            'app_version' => '2026.10.1',
            'user_id' => '0199aaaa-0000-7000-8000-000000000001',
            'actor_type' => 'user',
        ]);
});

it('omits the actor when nobody is authenticated', function (): void {
    $record = jsonLogLine(fn ($logger) => $logger->info('system.tick'));

    expect($record['extra'])->not->toHaveKey('user_id');
});

it('masks secret-looking context keys at any depth', function (): void {
    $record = jsonLogLine(fn ($logger) => $logger->warning('webhook.failed', [
        'password' => 'hunter2',
        'client_secret' => 'abc',
        'request' => ['headers' => ['Authorization' => 'Bearer xyz', 'Cookie' => 'shp_session=1'], 'url' => '/hooks'],
        'api_key' => 'k',
        'ticket_id' => '0199',
    ]));

    expect($record['context'])->toBe([
        'password' => '[redacted]',
        'client_secret' => '[redacted]',
        'request' => ['headers' => ['Authorization' => '[redacted]', 'Cookie' => '[redacted]'], 'url' => '/hooks'],
        'api_key' => '[redacted]',
        'ticket_id' => '0199',
    ]);
});

it('uses the processors on the production stderr channel', function (): void {
    expect(config('logging.channels.stderr.processors'))
        ->toContain(ContextProcessor::class, RedactSecretsProcessor::class);
});
