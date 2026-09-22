<?php

declare(strict_types=1);
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\BroadcastManager;

/**
 * Points broadcasting at Reverb with test credentials. Channel authorisation signs locally, so no
 * server is contacted; broadcasts themselves are faked by the tests that send them.
 */
function useReverbBroadcaster(): void
{
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
        'helpdesk.features.realtime' => true,
    ]);
}

/**
 * A broadcaster that records what would reach Reverb, with the tenant context it ran in.
 */
final class RecordingBroadcaster extends Broadcaster
{
    /** @var list<array{channels: list<string>, event: string, payload: array<string, mixed>, tenant: mixed}> */
    public static array $sent = [];

    public static bool $failing = false;

    public function auth($request): mixed
    {
        return null;
    }

    public function validAuthenticationResponse($request, $result): mixed
    {
        return null;
    }

    public function broadcast(array $channels, $event, array $payload = []): void
    {
        if (self::$failing) {
            throw new BroadcastException('Reverb is not running');
        }

        unset($payload['socket']);
        self::$sent[] = ['channels' => array_map('strval', $channels), 'event' => $event, 'payload' => $payload, 'tenant' => tenant('id')];
    }
}

function useRecordingBroadcaster(): void
{
    RecordingBroadcaster::$sent = [];
    RecordingBroadcaster::$failing = false;
    app(BroadcastManager::class)->extend('recording', fn (): RecordingBroadcaster => new RecordingBroadcaster);
    config([
        'broadcasting.connections.recording' => ['driver' => 'recording'],
        'broadcasting.default' => 'recording',
        'helpdesk.features.realtime' => true,
    ]);
}
