<?php

declare(strict_types=1);

use App\Modules\Realtime\Health\ReverbCheck;
use Spatie\Health\Enums\Status;

it('reports Reverb reachable when its port accepts connections', function (): void {
    $server = stream_socket_server('tcp://127.0.0.1:0');
    [, $port] = explode(':', (string) stream_socket_get_name($server, false));
    config(['broadcasting.connections.reverb.options' => ['host' => '127.0.0.1', 'port' => (int) $port]]);

    expect(ReverbCheck::new()->run()->status)->toBe(Status::ok());

    fclose($server);
});

it('fails when Reverb is down', function (): void {
    config(['broadcasting.connections.reverb.options' => ['host' => '127.0.0.1', 'port' => 1]]);

    expect(ReverbCheck::new()->run()->status)->toBe(Status::failed());
});
