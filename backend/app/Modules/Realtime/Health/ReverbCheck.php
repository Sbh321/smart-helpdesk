<?php

declare(strict_types=1);

namespace App\Modules\Realtime\Health;

use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Reverb accepts connections on the internal network (docs/11-operations/observability.md). Only
 * registered when BROADCAST_CONNECTION=reverb; without it the SPA polls and there is nothing to check.
 */
final class ReverbCheck extends Check
{
    public function run(): Result
    {
        $host = (string) config('broadcasting.connections.reverb.options.host', 'reverb');
        $port = (int) config('broadcasting.connections.reverb.options.port', 8080);

        $socket = @fsockopen($host, $port, $errorCode, $errorMessage, 1.0);
        if ($socket === false) {
            return Result::make()->failed('Reverb does not accept connections; the SPA falls back to polling.')
                ->meta(['host' => $host, 'port' => $port]);
        }
        fclose($socket);

        return Result::make()->ok()->shortSummary('reachable')->meta(['host' => $host, 'port' => $port]);
    }
}
